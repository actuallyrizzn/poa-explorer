#!/usr/bin/env python3
"""Stream-strip minepoa /api/epochs JSON into a slim SQLite store.

Never loads the full document into memory: walks the top-level array (or
``.body`` array) object-by-object, keeps only fields needed for last-close,
settled index, and Reports, then unlinks nothing (caller deletes the raw file).

Stdlib only — safe on the shared multihost box.
"""
from __future__ import annotations

import argparse
import json
import os
import sqlite3
import sys
from typing import Any, Iterator, TextIO


KEEP_CLOSE_TOP = ("epoch", "ts", "pool", "note", "rewards")
KEEP_BASIS = (
    "pending",
    "settled",
    "established_after",
    "attestations",
    "final_payout",
)


def _skip_ws(f: TextIO) -> str:
    while True:
        ch = f.read(1)
        if ch == "":
            return ""
        if not ch.isspace():
            return ch


def _read_string(f: TextIO, first: str) -> str:
    out = [first]
    esc = False
    while True:
        ch = f.read(1)
        if ch == "":
            raise ValueError("unterminated string")
        out.append(ch)
        if esc:
            esc = False
            continue
        if ch == "\\":
            esc = True
            continue
        if ch == '"':
            return "".join(out)


def _read_value(f: TextIO, first: str) -> str:
    """Return the raw JSON text of one value starting with first char."""
    if first == '"':
        return _read_string(f, first)
    if first == "{":
        return _read_object(f, first)
    if first == "[":
        return _read_array(f, first)
    # number / true / false / null
    out = [first]
    while True:
        ch = f.read(1)
        if ch == "":
            break
        if ch in ",]}":
            # put back by seeking -1; TextIO may not support — use unread buffer
            f.seek(f.tell() - 1)
            break
        if ch.isspace():
            break
        out.append(ch)
    return "".join(out)


def _read_object(f: TextIO, first: str = "{") -> str:
    assert first == "{"
    out = ["{"]
    depth = 1
    in_str = False
    esc = False
    while depth:
        ch = f.read(1)
        if ch == "":
            raise ValueError("unterminated object")
        out.append(ch)
        if in_str:
            if esc:
                esc = False
            elif ch == "\\":
                esc = True
            elif ch == '"':
                in_str = False
            continue
        if ch == '"':
            in_str = True
        elif ch == "{":
            depth += 1
        elif ch == "}":
            depth -= 1
        elif ch == "[":
            # track arrays inside object via same depth? brackets don't change brace depth
            # but we need to not treat braces inside strings — already handled.
            # Arrays: still need to skip correctly for string/brace rules only.
            pass
        # Handle nested arrays by also tracking bracket depth for correctness
    return "".join(out)


def _read_array(f: TextIO, first: str = "[") -> str:
    """Read a raw array (used when a value is an array). Brace/bracket aware."""
    assert first == "["
    out = ["["]
    depth = 1
    in_str = False
    esc = False
    while depth:
        ch = f.read(1)
        if ch == "":
            raise ValueError("unterminated array")
        out.append(ch)
        if in_str:
            if esc:
                esc = False
            elif ch == "\\":
                esc = True
            elif ch == '"':
                in_str = False
            continue
        if ch == '"':
            in_str = True
        elif ch == "[":
            depth += 1
        elif ch == "]":
            depth -= 1
        elif ch == "{":
            # nested object — keep reading; depth is bracket-only here so
            # braces inside are fine as long as strings are tracked.
            pass
    return "".join(out)


def _seek_body_array(f: TextIO) -> None:
    """Advance file so the next read is the first char of the ``body`` array value.

    Used for large wrapped explorer cache files ``{_cache,...,body:[...]}`` so we
    never ``json.loads`` the whole blob. Small error objects still fall through.
    """
    # Scan for "body" key then ':' then '[' without loading the file.
    # State machine over raw text; ignore matches inside strings.
    in_str = False
    esc = False
    buf: list[str] = []
    while True:
        ch = f.read(1)
        if ch == "":
            raise ValueError("wrapped JSON ended before body array")
        if in_str:
            if esc:
                esc = False
            elif ch == "\\":
                esc = True
            elif ch == '"':
                in_str = False
                token = "".join(buf)
                buf = []
                if token == "body":
                    # skip ws, colon, ws → expect [
                    nxt = _skip_ws(f)
                    if nxt != ":":
                        continue
                    nxt = _skip_ws(f)
                    if nxt != "[":
                        raise ValueError("body key was not an array")
                    # put '[' back for the array iterator
                    f.seek(f.tell() - 1)
                    return
            else:
                buf.append(ch)
            continue
        if ch == '"':
            in_str = True
            buf = []


def _iter_array_objects(f: TextIO) -> Iterator[dict[str, Any]]:
    first = _skip_ws(f)
    if first != "[":
        raise ValueError(f"expected JSON array, got {first!r}")
    while True:
        ch = _skip_ws(f)
        if ch == "" or ch == "]":
            return
        if ch == ",":
            ch = _skip_ws(f)
        if ch == "]":
            return
        if ch != "{":
            raise ValueError(f"expected object in array, got {ch!r}")
        raw = _read_object(f, ch)
        obj = json.loads(raw)
        if isinstance(obj, dict):
            yield obj


def iter_top_array_objects(path: str) -> Iterator[dict[str, Any]]:
    """Yield each object in a top-level JSON array, or in ``.body`` if wrapped."""
    with open(path, "r", encoding="utf-8") as f:
        first = _skip_ws(f)
        if first == "":
            return
        if first == "{":
            # Peek a small prefix for error objects; otherwise stream-seek body.
            peek = first + f.read(4096)
            try:
                # If the whole document fits in peek and is an error, raise cleanly.
                if '"error"' in peek and '"body"' not in peek and peek.rstrip().endswith("}"):
                    data = json.loads(peek)
                    if isinstance(data, dict) and data.get("error"):
                        raise ValueError(f"origin error: {data.get('error')}")
            except json.JSONDecodeError:
                pass
            # Rewind and stream-seek to body array (large cache envelopes).
            f.seek(0)
            _seek_body_array(f)
            yield from _iter_array_objects(f)
            return
        if first != "[":
            raise ValueError(f"expected JSON array or object, got {first!r}")
        # put '[' back
        f.seek(f.tell() - 1)
        yield from _iter_array_objects(f)


def slim_close(close: dict[str, Any]) -> dict[str, Any]:
    out: dict[str, Any] = {}
    for k in KEEP_CLOSE_TOP:
        if k in close:
            out[k] = close[k]
    basis_in = close.get("basis")
    if isinstance(basis_in, dict):
        basis: dict[str, Any] = {}
        for k in KEEP_BASIS:
            if k in basis_in:
                basis[k] = basis_in[k]
        out["basis"] = basis
    # Keep empty placeholders so close-key agreement still sees the minepoa key set.
    # Full payloads stay on origin; commented PHP paths can restore heavy passthrough later.
    for heavy in ("challenges", "propagation", "weights"):
        out[heavy] = [] if heavy != "propagation" else {}
        if isinstance(close.get(heavy), dict):
            out[heavy] = {}
        elif isinstance(close.get(heavy), list):
            out[heavy] = []
    return out


def write_sqlite(db_path: str, closes: list[dict[str, Any]]) -> dict[str, int]:
    os.makedirs(os.path.dirname(db_path) or ".", exist_ok=True)
    tmp = db_path + ".tmp"
    if os.path.exists(tmp):
        os.unlink(tmp)
    conn = sqlite3.connect(tmp)
    try:
        conn.execute("PRAGMA journal_mode=WAL")
        conn.execute(
            "CREATE TABLE closes ("
            "epoch INTEGER PRIMARY KEY,"
            "ts INTEGER,"
            "json TEXT NOT NULL)"
        )
        conn.execute(
            "CREATE TABLE settled ("
            "answer_txid TEXT NOT NULL,"
            "close_epoch INTEGER NOT NULL,"
            "close_ts INTEGER,"
            "\"from\" TEXT,"
            "passed INTEGER,"
            "PRIMARY KEY (answer_txid, close_epoch))"
        )
        conn.execute(
            "CREATE INDEX settled_answer ON settled(answer_txid)"
        )
        n_settled = 0
        for c in closes:
            # epoch 0 is valid — do not use `or -1` (0 is falsy in Python).
            if "epoch" not in c or c.get("epoch") is None:
                continue
            try:
                epoch = int(c["epoch"])
            except (TypeError, ValueError):
                continue
            if epoch < 0:
                continue
            ts = c.get("ts")
            ts_i = int(ts) if isinstance(ts, (int, float)) or (isinstance(ts, str) and ts.isdigit()) else None
            conn.execute(
                "INSERT OR REPLACE INTO closes(epoch, ts, json) VALUES (?,?,?)",
                (epoch, ts_i, json.dumps(c, separators=(",", ":"), ensure_ascii=False)),
            )
            settled = (c.get("basis") or {}).get("settled") if isinstance(c.get("basis"), dict) else None
            if not isinstance(settled, list):
                continue
            for s in settled:
                if not isinstance(s, dict):
                    continue
                aid = str(s.get("answer") or "").lower()
                if not aid:
                    continue
                passed = s.get("passed")
                passed_i = None if passed is None else (1 if passed else 0)
                conn.execute(
                    "INSERT OR REPLACE INTO settled(answer_txid, close_epoch, close_ts, \"from\", passed) "
                    "VALUES (?,?,?,?,?)",
                    (aid, epoch, ts_i, s.get("from"), passed_i),
                )
                n_settled += 1
        conn.commit()
    finally:
        conn.close()
    os.replace(tmp, db_path)
    return {"closes": len(closes), "settled_rows": n_settled}


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("raw_json", help="Path to downloaded /api/epochs JSON")
    ap.add_argument("sqlite_out", help="Output SQLite path (atomic replace)")
    ap.add_argument(
        "--slim-jsonl",
        default="",
        help="Optional path to write slim closes as JSONL (for PHP reports)",
    )
    args = ap.parse_args()

    closes: list[dict[str, Any]] = []
    for obj in iter_top_array_objects(args.raw_json):
        closes.append(slim_close(obj))
    if not closes:
        raise ValueError("no close records after strip (empty or paused origin)")
    closes.sort(key=lambda c: int(c.get("epoch") or 0))

    stats = write_sqlite(args.sqlite_out, closes)
    if args.slim_jsonl:
        tmp = args.slim_jsonl + ".tmp"
        with open(tmp, "w", encoding="utf-8") as out:
            for c in closes:
                out.write(json.dumps(c, separators=(",", ":"), ensure_ascii=False))
                out.write("\n")
        os.replace(tmp, args.slim_jsonl)

    print(
        json.dumps(
            {
                "ok": True,
                "closes": stats["closes"],
                "settled_rows": stats["settled_rows"],
                "sqlite": args.sqlite_out,
            }
        ),
        flush=True,
    )
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as e:
        print(json.dumps({"ok": False, "error": f"{type(e).__name__}: {e}"}), flush=True)
        raise SystemExit(1)

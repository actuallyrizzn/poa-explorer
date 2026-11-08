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


KEEP_CLOSE_TOP = ("epoch", "ts", "pool", "note")


def slim_panel(panel: Any) -> list[Any]:
    if not isinstance(panel, list):
        return []
    out: list[Any] = []
    for x in panel:
        if isinstance(x, (str, int, float, bool)) or x is None:
            out.append(x)
        elif isinstance(x, dict):
            out.append(
                x.get("id")
                or x.get("addr")
                or x.get("address")
                or x.get("grader")
                or next((v for v in x.values() if isinstance(v, str)), None)
                or "seat"
            )
        else:
            out.append(str(x)[:96])
    return out


def slim_grades(grades: Any) -> Any:
    if grades is None:
        return None
    if not isinstance(grades, list):
        return grades
    out = []
    for g in grades:
        if not isinstance(g, dict):
            continue
        out.append(
            {
                k: g[k]
                for k in ("score", "ts", "grader")
                if k in g
            }
        )
    return out


def slim_pending_map(pending: Any) -> dict[str, Any]:
    if not isinstance(pending, dict):
        return {}
    out: dict[str, Any] = {}
    for aid, p in pending.items():
        if not isinstance(p, dict):
            continue
        row: dict[str, Any] = {}
        for k in ("kind", "since", "originalPassed"):
            if k in p:
                row[k] = p[k]
        if "panel" in p:
            row["panel"] = slim_panel(p.get("panel"))
        if "grades" in p:
            row["grades"] = slim_grades(p.get("grades"))
        out[str(aid)] = row
    return out


def slim_settled_list(settled: Any) -> list[dict[str, Any]]:
    if not isinstance(settled, list):
        return []
    out: list[dict[str, Any]] = []
    for s in settled:
        if not isinstance(s, dict):
            continue
        row = {k: s[k] for k in ("answer", "from", "passed") if k in s}
        if row.get("answer"):
            out.append(row)
    return out


def slim_rewards(rewards: Any) -> Any:
    """Keep address→amount map but drop non-positive noise when huge."""
    if not isinstance(rewards, dict):
        return rewards if isinstance(rewards, list) else ([] if rewards is None else rewards)
    # Cap: keep only positive amounts (reports paid count).
    slim = {}
    for addr, amt in rewards.items():
        try:
            if amt is None or amt == "":
                continue
            if float(amt) > 0:
                slim[str(addr)] = amt
        except (TypeError, ValueError):
            continue
    return slim


def slim_close(close: dict[str, Any]) -> dict[str, Any]:
    out: dict[str, Any] = {}
    for k in KEEP_CLOSE_TOP:
        if k in close:
            out[k] = close[k]
    basis_in = close.get("basis")
    basis: dict[str, Any] = {}
    if isinstance(basis_in, dict):
        if "pending" in basis_in:
            basis["pending"] = slim_pending_map(basis_in.get("pending"))
        if "settled" in basis_in:
            basis["settled"] = slim_settled_list(basis_in.get("settled"))
        # Counts-only for bulky side maps (reports only needs len()).
        for k in ("established_after", "attestations"):
            v = basis_in.get(k)
            if isinstance(v, list):
                basis[k] = [None] * len(v)  # preserve count, drop payloads
            elif isinstance(v, dict):
                basis[k] = {str(i): None for i in range(len(v))}
            elif v is not None:
                basis[k] = v
        fp = basis_in.get("final_payout")
        if isinstance(fp, dict):
            capped = fp.get("capped")
            n = len(capped) if isinstance(capped, (list, dict)) else 0
            basis["final_payout"] = {"capped": [None] * n}
        elif fp is not None:
            basis["final_payout"] = fp
    out["basis"] = basis
    if "rewards" in close:
        out["rewards"] = slim_rewards(close.get("rewards"))
    else:
        out["rewards"] = []
    # Placeholders so close-key agreement still sees the minepoa key set.
    for heavy in ("challenges", "propagation", "weights"):
        val = close.get(heavy)
        if isinstance(val, dict):
            out[heavy] = {}
        else:
            out[heavy] = []
    return out


def write_sqlite_streaming(
    db_path: str,
    closes_iter: Iterator[dict[str, Any]],
    jsonl_path: str = "",
) -> dict[str, int]:
    """Insert one slim close at a time — never hold the full close list."""
    os.makedirs(os.path.dirname(db_path) or ".", exist_ok=True)
    tmp = db_path + ".tmp"
    if os.path.exists(tmp):
        os.unlink(tmp)
    jsonl_tmp = (jsonl_path + ".tmp") if jsonl_path else ""
    if jsonl_tmp and os.path.exists(jsonl_tmp):
        os.unlink(jsonl_tmp)
    conn = sqlite3.connect(tmp)
    n_closes = 0
    n_settled = 0
    jl = open(jsonl_tmp, "w", encoding="utf-8") if jsonl_tmp else None
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
        conn.execute("CREATE INDEX settled_answer ON settled(answer_txid)")
        for c in closes_iter:
            if "epoch" not in c or c.get("epoch") is None:
                continue
            try:
                epoch = int(c["epoch"])
            except (TypeError, ValueError):
                continue
            if epoch < 0:
                continue
            ts = c.get("ts")
            ts_i = (
                int(ts)
                if isinstance(ts, (int, float))
                or (isinstance(ts, str) and ts.isdigit())
                else None
            )
            payload = json.dumps(c, separators=(",", ":"), ensure_ascii=False)
            conn.execute(
                "INSERT OR REPLACE INTO closes(epoch, ts, json) VALUES (?,?,?)",
                (epoch, ts_i, payload),
            )
            if jl is not None:
                jl.write(payload)
                jl.write("\n")
            n_closes += 1
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
            if n_closes % 5 == 0:
                conn.commit()
        conn.commit()
    finally:
        conn.close()
        if jl is not None:
            jl.close()
    if n_closes == 0:
        if os.path.exists(tmp):
            os.unlink(tmp)
        if jsonl_tmp and os.path.exists(jsonl_tmp):
            os.unlink(jsonl_tmp)
        raise ValueError("no close records after strip (empty or paused origin)")
    os.replace(tmp, db_path)
    if jsonl_tmp:
        os.replace(jsonl_tmp, jsonl_path)
    return {"closes": n_closes, "settled_rows": n_settled}


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

    def gen() -> Iterator[dict[str, Any]]:
        for obj in iter_top_array_objects(args.raw_json):
            yield slim_close(obj)

    stats = write_sqlite_streaming(args.sqlite_out, gen(), args.slim_jsonl or "")
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

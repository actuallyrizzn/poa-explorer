#!/usr/bin/env python3
"""Stdio MCP server for the PoA explorer. Stdlib only. Read-only. No keys.

Cursor / Claude Desktop example:

  {"command": "python3", "args": ["/path/to/poa-explorer/mcp/server.py"]}

Protocol: JSON-RPC 2.0 over stdio (Content-Length framing, or one JSON object per line).
"""

from __future__ import annotations

import json
import os
import sys
from pathlib import Path
from typing import Any

HERE = Path(__file__).resolve().parent
ROOT = HERE.parent
for candidate in (ROOT / "sdk" / "python", HERE):
    pkg = candidate / "poa_explorer" / "__init__.py"
    if pkg.is_file() and str(candidate) not in sys.path:
        sys.path.insert(0, str(candidate))

from poa_explorer import PoaExplorerClient, PoaExplorerError  # noqa: E402

PROTOCOL = "2024-11-05"
SERVER_NAME = "poa-explorer"
SERVER_VERSION = "0.3.1"
BASE = os.environ.get("POA_EXPLORER_BASE_URL", "https://poa.explorer.zero1.network").rstrip("/")

TOOLS = [
    {"name": "status", "description": "Chain tip and checkpoint", "inputSchema": {"type": "object", "properties": {}}},
    {"name": "txs", "description": "Recent transactions", "inputSchema": {"type": "object", "properties": {}}},
    {
        "name": "tx",
        "description": "One transaction by 64-hex id",
        "inputSchema": {
            "type": "object",
            "properties": {"id": {"type": "string"}},
            "required": ["id"],
        },
    },
    {
        "name": "address",
        "description": "One account by poa1… address (includes additive grading when present)",
        "inputSchema": {
            "type": "object",
            "properties": {"a": {"type": "string"}},
            "required": ["a"],
        },
    },
    {"name": "anchors", "description": "Base/Solana checkpoint receipts", "inputSchema": {"type": "object", "properties": {}}},
    {
        "name": "epochs",
        "description": "Closed epochs — same field names and timestamps as minepoa /api/epochs",
        "inputSchema": {"type": "object", "properties": {}},
    },
    {
        "name": "close",
        "description": "One close record by epoch number (same keys as minepoa /api/epochs rows)",
        "inputSchema": {
            "type": "object",
            "properties": {"epoch": {"type": "string"}},
            "required": ["epoch"],
        },
    },
    {"name": "challenges", "description": "Challenge index (large)", "inputSchema": {"type": "object", "properties": {}}},
    {
        "name": "pending",
        "description": "Last-close pending snapshot with panel size and age. Optional a= address filter.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "a": {"type": "string"},
                "without_panel": {"type": "boolean"},
                "limit": {"type": "integer"},
            },
        },
    },
    {"name": "changelog", "description": "Explorer API changelog", "inputSchema": {"type": "object", "properties": {}}},
]


def _client() -> PoaExplorerClient:
    return PoaExplorerClient(base_url=BASE)


def _call_tool(name: str, args: dict[str, Any]) -> Any:
    c = _client()
    if name == "status":
        return c.status()
    if name == "txs":
        return c.recent_txs()
    if name == "tx":
        return c.tx(str(args.get("id") or ""))
    if name == "address":
        return c.address(str(args.get("a") or args.get("address") or ""))
    if name == "anchors":
        return c.anchors()
    if name == "epochs":
        return c.epochs()
    if name == "close":
        return c.close(str(args.get("epoch") or ""))
    if name == "challenges":
        return c.challenges()
    if name == "pending":
        query: dict[str, str] = {}
        if args.get("a"):
            query["a"] = str(args["a"])
        if args.get("without_panel"):
            query["without_panel"] = "1"
        if args.get("limit") is not None:
            query["limit"] = str(int(args["limit"]))
        return c.pending(query or None)
    if name == "changelog":
        return c.changelog()
    raise ValueError("unknown tool: " + name)


def _result_ok(req_id: Any, result: Any) -> dict[str, Any]:
    return {"jsonrpc": "2.0", "id": req_id, "result": result}


def _result_err(req_id: Any, code: int, message: str) -> dict[str, Any]:
    return {"jsonrpc": "2.0", "id": req_id, "error": {"code": code, "message": message}}


def handle(msg: dict[str, Any]) -> dict[str, Any] | None:
    method = str(msg.get("method") or "")
    req_id = msg.get("id")
    params = msg.get("params") if isinstance(msg.get("params"), dict) else {}
    if method.startswith("notifications/") or req_id is None:
        return None
    if method == "initialize":
        return _result_ok(
            req_id,
            {
                "protocolVersion": PROTOCOL,
                "capabilities": {"tools": {"listChanged": False}},
                "serverInfo": {"name": SERVER_NAME, "version": SERVER_VERSION},
                "instructions": (
                    "Read-only Proof of Agent explorer. No wallet, no keys, never /api/hosted. "
                    "epochs/close match minepoa /api/epochs field names. "
                    "pending panel is last-close snapshot, not a live draw."
                ),
            },
        )
    if method == "ping":
        return _result_ok(req_id, {})
    if method == "tools/list":
        return _result_ok(req_id, {"tools": TOOLS})
    if method == "tools/call":
        name = str(params.get("name") or "")
        args = params.get("arguments") if isinstance(params.get("arguments"), dict) else {}
        try:
            payload = _call_tool(name, args)
            text = json.dumps(payload, indent=2, default=str)
            return _result_ok(
                req_id,
                {"content": [{"type": "text", "text": text}], "isError": False},
            )
        except (PoaExplorerError, ValueError, TypeError) as exc:
            return _result_ok(
                req_id,
                {"content": [{"type": "text", "text": str(exc)}], "isError": True},
            )
    return _result_err(req_id, -32601, "method not found: " + method)


def _write(msg: dict[str, Any]) -> None:
    raw = json.dumps(msg, separators=(",", ":")).encode("utf-8")
    sys.stdout.buffer.write(b"Content-Length: " + str(len(raw)).encode() + b"\r\n\r\n")
    sys.stdout.buffer.write(raw)
    sys.stdout.buffer.flush()


def _read() -> dict[str, Any] | None:
    header_line = sys.stdin.buffer.readline()
    if not header_line:
        return None
    if header_line[:1] == b"{":
        return json.loads(header_line.decode("utf-8"))
    headers: dict[str, str] = {}
    line = header_line
    while line not in (b"\n", b"\r\n", b""):
        decoded = line.decode("utf-8")
        if ":" in decoded:
            k, v = decoded.split(":", 1)
            headers[k.strip().lower()] = v.strip()
        line = sys.stdin.buffer.readline()
        if not line:
            break
    n = int(headers.get("content-length") or "0")
    if n <= 0:
        return None
    body = sys.stdin.buffer.read(n)
    return json.loads(body.decode("utf-8"))


def main() -> int:
    while True:
        try:
            msg = _read()
        except json.JSONDecodeError:
            continue
        if msg is None:
            return 0
        if not isinstance(msg, dict):
            continue
        out = handle(msg)
        if out is not None:
            _write(out)


if __name__ == "__main__":
    raise SystemExit(main())

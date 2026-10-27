#!/usr/bin/env python3
from __future__ import annotations

import importlib.util
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "sdk" / "python"))

spec = importlib.util.spec_from_file_location("poa_mcp_server", ROOT / "mcp" / "server.py")
mod = importlib.util.module_from_spec(spec)
assert spec.loader is not None
spec.loader.exec_module(mod)


class McpServerTest(unittest.TestCase):
    def test_initialize(self) -> None:
        out = mod.handle({"jsonrpc": "2.0", "id": 1, "method": "initialize", "params": {}})
        self.assertEqual("poa-explorer", out["result"]["serverInfo"]["name"])
        self.assertEqual("0.3.1", out["result"]["serverInfo"]["version"])
        self.assertIn("tools", out["result"]["capabilities"])

    def test_tools_list(self) -> None:
        out = mod.handle({"jsonrpc": "2.0", "id": 2, "method": "tools/list", "params": {}})
        names = [t["name"] for t in out["result"]["tools"]]
        for need in ("status", "epochs", "close", "pending", "changelog", "address"):
            self.assertIn(need, names)

    def test_notification_is_none(self) -> None:
        self.assertIsNone(mod.handle({"jsonrpc": "2.0", "method": "notifications/initialized"}))

    def test_unknown_method(self) -> None:
        out = mod.handle({"jsonrpc": "2.0", "id": 9, "method": "nope"})
        self.assertEqual(-32601, out["error"]["code"])

    def test_ping(self) -> None:
        out = mod.handle({"jsonrpc": "2.0", "id": 3, "method": "ping"})
        self.assertEqual({}, out["result"])

    def test_unknown_tool_is_error_content(self) -> None:
        out = mod.handle({
            "jsonrpc": "2.0",
            "id": 4,
            "method": "tools/call",
            "params": {"name": "not-a-tool", "arguments": {}},
        })
        self.assertTrue(out["result"]["isError"])


if __name__ == "__main__":
    unittest.main()

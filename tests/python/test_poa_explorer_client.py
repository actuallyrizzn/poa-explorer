#!/usr/bin/env python3
from __future__ import annotations

import io
import json
import sys
import unittest
from pathlib import Path
from unittest.mock import patch
from urllib.error import HTTPError, URLError

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "sdk" / "python"))

from poa_explorer import PoaExplorerClient, PoaExplorerError  # noqa: E402


class _Resp:
    def __init__(self, payload: dict) -> None:
        self._raw = json.dumps(payload).encode()

    def read(self) -> bytes:
        return self._raw

    def __enter__(self):
        return self

    def __exit__(self, *args):
        return False


class ClientTest(unittest.TestCase):
    def test_status(self) -> None:
        payload = {"ok": True, "error": None, "cache": "fresh", "body": {"checkpoint": {"height": 3}}}
        with patch("poa_explorer.client.urllib.request.urlopen", return_value=_Resp(payload)):
            out = PoaExplorerClient().status()
        self.assertTrue(out["ok"])
        self.assertEqual(3, out["body"]["checkpoint"]["height"])

    def test_ok_false(self) -> None:
        payload = {"ok": False, "error": "not a PoA address", "cache": "miss", "body": None}
        with patch("poa_explorer.client.urllib.request.urlopen", return_value=_Resp(payload)):
            with self.assertRaises(PoaExplorerError) as ctx:
                PoaExplorerClient().address("nope")
        self.assertIn("address", str(ctx.exception))

    def test_http_error(self) -> None:
        body = json.dumps({"ok": False, "error": "boom", "cache": "miss", "body": None}).encode()
        err = HTTPError("http://x/api/tx.php", 400, "bad", hdrs=None, fp=io.BytesIO(body))
        with patch("poa_explorer.client.urllib.request.urlopen", side_effect=err):
            with self.assertRaises(PoaExplorerError):
                PoaExplorerClient().tx("00" * 32)

    def test_unreachable(self) -> None:
        with patch("poa_explorer.client.urllib.request.urlopen", side_effect=URLError("down")):
            with self.assertRaises(PoaExplorerError):
                PoaExplorerClient().anchors()

    def test_other_getters(self) -> None:
        payload = {"ok": True, "error": None, "cache": "hit", "body": []}
        with patch("poa_explorer.client.urllib.request.urlopen", return_value=_Resp(payload)):
            c = PoaExplorerClient()
            self.assertTrue(c.recent_txs()["ok"])
            self.assertTrue(c.epochs()["ok"])
            self.assertTrue(c.challenges()["ok"])
            self.assertTrue(c.tx("ab" * 32)["ok"])
            self.assertTrue(c.changelog()["ok"])
            self.assertTrue(c.close(7)["ok"])
            self.assertTrue(c.pending({"a": "poa1x"})["ok"])
            self.assertTrue(c.reports()["ok"])


if __name__ == "__main__":
    unittest.main()

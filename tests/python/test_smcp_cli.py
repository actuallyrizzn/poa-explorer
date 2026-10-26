#!/usr/bin/env python3
from __future__ import annotations

import json
import subprocess
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
CLI = ROOT / "smcp_plugin" / "poa_explorer" / "cli.py"


class SmcpCliTest(unittest.TestCase):
    def test_help_all(self) -> None:
        out = subprocess.check_output([sys.executable, str(CLI), "--help-all"], text=True)
        data = json.loads(out)
        self.assertEqual("poa_explorer", data["plugin"])
        self.assertIn("status", data["commands"])
        self.assertIn("none", data["auth"])

    def test_tx_requires_id(self) -> None:
        proc = subprocess.run([sys.executable, str(CLI), "tx"], capture_output=True, text=True)
        self.assertEqual(2, proc.returncode)
        self.assertIn("--id", proc.stdout)

    def test_address_requires_a(self) -> None:
        proc = subprocess.run([sys.executable, str(CLI), "address"], capture_output=True, text=True)
        self.assertEqual(2, proc.returncode)
        self.assertIn("--a", proc.stdout)


if __name__ == "__main__":
    unittest.main()

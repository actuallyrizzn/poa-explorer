#!/usr/bin/env python3
"""PoA explorer SMCP CLI — public read-only JSON. No API key."""

from __future__ import annotations

import argparse
import json
import os
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "sdk" / "python"))

from poa_explorer import PoaExplorerClient, PoaExplorerError  # noqa: E402

BASE = os.environ.get("POA_EXPLORER_BASE_URL", "https://poa.explorer.zero1.network").rstrip("/")

COMMANDS = ("status", "txs", "tx", "address", "anchors", "epochs", "challenges", "tool-help")


def main() -> int:
    p = argparse.ArgumentParser(description="PoA explorer SMCP plugin (read-only, no key)")
    p.add_argument("--base-url", default=BASE)
    p.add_argument("--help-all", action="store_true")
    sub = p.add_subparsers(dest="cmd")
    sub.add_parser("status")
    sub.add_parser("txs")
    txp = sub.add_parser("tx")
    txp.add_argument("--id", required=False, default="")
    adp = sub.add_parser("address")
    adp.add_argument("--a", "--address", dest="a", default="")
    sub.add_parser("anchors")
    sub.add_parser("epochs")
    sub.add_parser("challenges")
    sub.add_parser("tool-help")

    args = p.parse_args()
    if args.help_all or not args.cmd or args.cmd == "tool-help":
        print(json.dumps({
            "plugin": "poa_explorer",
            "commands": list(COMMANDS),
            "auth": "none — public read-only",
            "base": args.base_url,
        }, indent=2))
        return 0

    client = PoaExplorerClient(base_url=args.base_url)
    try:
        if args.cmd == "status":
            out = client.status()
        elif args.cmd == "txs":
            out = client.recent_txs()
        elif args.cmd == "tx":
            if not args.id:
                print(json.dumps({"status": "error", "error": "--id required"}))
                return 2
            out = client.tx(args.id)
        elif args.cmd == "address":
            if not args.a:
                print(json.dumps({"status": "error", "error": "--a required"}))
                return 2
            out = client.address(args.a)
        elif args.cmd == "anchors":
            out = client.anchors()
        elif args.cmd == "epochs":
            out = client.epochs()
        elif args.cmd == "challenges":
            out = client.challenges()
        else:
            print(json.dumps({"status": "error", "error": "unknown command"}))
            return 2
        print(json.dumps({"status": "success", "result": out}, indent=2, default=str))
        return 0
    except PoaExplorerError as exc:
        print(json.dumps({"status": "error", "error": str(exc), "payload": exc.payload}, default=str))
        return 1


if __name__ == "__main__":
    raise SystemExit(main())

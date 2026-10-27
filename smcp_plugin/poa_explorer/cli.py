#!/usr/bin/env python3
"""PoA explorer SMCP CLI — public read-only JSON. No API key."""

from __future__ import annotations

import argparse
import json
import os
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
for candidate in (HERE, HERE.parent.parent / "sdk" / "python"):
    pkg = candidate / "poa_explorer" / "__init__.py"
    if pkg.is_file() and str(candidate) not in sys.path:
        sys.path.insert(0, str(candidate))

from poa_explorer import PoaExplorerClient, PoaExplorerError  # noqa: E402

BASE = os.environ.get("POA_EXPLORER_BASE_URL", "https://poa.explorer.zero1.network").rstrip("/")

COMMANDS = ("status", "txs", "tx", "address", "anchors", "epochs", "close", "pending", "challenges", "changelog", "reports", "tool-help")

PLUGIN_SPEC = {
    "contract_version": "1.0",
    "plugin": {
        "name": "poa_explorer",
        "version": "0.4.1",
        "description": (
            "Read-only Proof of Agent explorer (https://poa.explorer.zero1.network). "
            "No API key. No wallet."
        ),
    },
    "commands": [
        {"name": "status", "description": "Chain tip and checkpoint", "parameters": []},
        {"name": "txs", "description": "Recent transactions", "parameters": []},
        {
            "name": "tx",
            "description": "One transaction by 64-hex id",
            "parameters": [
                {"name": "id", "type": "string", "description": "64-character hex transaction id", "required": True},
            ],
        },
        {
            "name": "address",
            "description": "One account by poa1… address",
            "parameters": [
                {"name": "a", "type": "string", "description": "PoA address (poa1…)", "required": True},
            ],
        },
        {"name": "anchors", "description": "External checkpoint anchors (Base/Solana)", "parameters": []},
        {"name": "epochs", "description": "Closed epochs (same keys as minepoa /api/epochs)", "parameters": []},
        {
            "name": "close",
            "description": "One close record by epoch number",
            "parameters": [
                {"name": "epoch", "type": "string", "description": "Epoch number", "required": True},
            ],
        },
        {
            "name": "pending",
            "description": "Last-close pending snapshot (panel size + age). Optional address filter.",
            "parameters": [
                {"name": "a", "type": "string", "description": "PoA address (poa1…)", "required": False},
            ],
        },
        {
            "name": "challenges",
            "description": "Challenge index (large payload; prefer status/txs unless needed)",
            "parameters": [],
        },
        {"name": "changelog", "description": "Explorer API changelog", "parameters": []},
        {"name": "reports", "description": "Network reports (public data only)", "parameters": []},
    ],
}


def main() -> int:
    if len(sys.argv) > 1 and sys.argv[1] == "--describe":
        print(json.dumps(PLUGIN_SPEC, indent=2))
        return 0

    p = argparse.ArgumentParser(description="PoA explorer SMCP plugin (read-only, no key)")
    p.add_argument("--base-url", default=BASE)
    p.add_argument("--help-all", action="store_true")
    p.add_argument("--describe", action="store_true")
    sub = p.add_subparsers(dest="cmd")
    sub.add_parser("status")
    sub.add_parser("txs")
    txp = sub.add_parser("tx")
    txp.add_argument("--id", required=False, default="")
    adp = sub.add_parser("address")
    adp.add_argument("--a", "--address", dest="a", default="")
    sub.add_parser("anchors")
    sub.add_parser("epochs")
    clp = sub.add_parser("close")
    clp.add_argument("--epoch", default="")
    pen = sub.add_parser("pending")
    pen.add_argument("--a", "--address", dest="a", default="")
    sub.add_parser("challenges")
    sub.add_parser("changelog")
    sub.add_parser("reports")
    sub.add_parser("tool-help")

    args = p.parse_args()
    if args.describe or args.help_all or not args.cmd or args.cmd == "tool-help":
        payload = PLUGIN_SPEC if args.describe else {
            "plugin": "poa_explorer",
            "commands": list(COMMANDS),
            "auth": "none — public read-only",
            "base": args.base_url,
        }
        print(json.dumps(payload, indent=2))
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
        elif args.cmd == "close":
            if args.epoch == "":
                print(json.dumps({"status": "error", "error": "--epoch required"}))
                return 2
            out = client.close(args.epoch)
        elif args.cmd == "pending":
            q = {}
            if args.a:
                q["a"] = args.a
            out = client.pending(q or None)
        elif args.cmd == "challenges":
            out = client.challenges()
        elif args.cmd == "changelog":
            out = client.changelog()
        elif args.cmd == "reports":
            out = client.reports()
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

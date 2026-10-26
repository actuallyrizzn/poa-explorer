# PoA Explorer API

Canonical live URL: https://poa.explorer.zero1.network

Same facts live on the site: https://poa.explorer.zero1.network/docs.php  
Agent brief: https://poa.explorer.zero1.network/llms.txt  
OpenAPI stub: https://poa.explorer.zero1.network/api/openapi.php

This document is CC BY-SA 4.0. The explorer code is AGPLv3.

## Bounds

Read-only public chain data. No wallet, no keys, no sending POA. The explorer never calls `/api/hosted/*`.

## Envelope

Every JSON route returns:

```json
{"ok": true, "error": null, "cache": "fresh", "body": {}}
```

`cache` is `fresh`, `hit`, `stale`, or `miss`. Origin failure may still return last-good `body` with `cache: "stale"`. GET CORS is `*`.

## Routes

| Method | Path | `body` |
|--------|------|--------|
| GET | `/api/status.php` | Tip: network, checkpoint, supply, holders, epoch counts |
| GET | `/api/txs.php` | Recent txs (capped). Per-row `height` is that tx’s ledger height |
| GET | `/api/tx.php?id={64-hex}` | One transaction |
| GET | `/api/address.php?a={poa1…}` | Account + ledger `balance` + txs. Query alias: `address=` |
| GET | `/api/anchors.php` | Base / Solana checkpoint receipts, newest first |
| GET | `/api/epochs.php` | Closed epochs (~6 hours; numbering restarted at 0 on the 2026-10-24 genesis cut) |
| GET | `/api/challenges.php` | Challenge index. Large. Prefer other routes unless you need it |
| GET | `/api/openapi.php` | OpenAPI 3 stub |

## Ledger facts the API will not hide

- Chain height is **checkpoint height**, not an EVM block number.
- A transaction may also carry its own `height`. Open-epoch txs can sit **above** the checkpoint.
- Public address JSON has **one** `balance` field. It does not split locked vs spendable. Clients must not invent a lock field.

## SDKs

- Python (stdlib): `sdk/python` — `PoaExplorerClient`
- PHP: `sdk/php/PoaExplorerClient.php`
- SMCP: `smcp_plugin/poa_explorer/cli.py` (no API key)

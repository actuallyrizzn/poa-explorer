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
{"ok": true, "error": null, "cache": "fresh", "version": "0.4.2", "body": {}}
```

`version` is the explorer API version (changelog: `/api/changelog.php`). Changes are additive. `cache` is `fresh`, `hit`, `stale`, or `miss`. Origin failure may still return last-good `body` with `cache: "stale"`. GET CORS is `*`.

## Routes

| Method | Path | `body` |
|--------|------|--------|
| GET | `/api/status.php` | Tip: network, checkpoint, supply, holders, epoch counts |
| GET | `/api/txs.php` | Recent txs (capped). Per-row `height` is that tx’s ledger height |
| GET | `/api/tx.php?id={64-hex}` | One transaction |
| GET | `/api/address.php?a={poa1…}` | Account + ledger `balance` + txs. Query alias: `address=` |
| GET | `/api/anchors.php` | Base / Solana checkpoint receipts, newest first |
| GET | `/api/epochs.php` | Closed epochs. `body` is the minepoa `/api/epochs` list with the same field names and timestamps (`epoch`, `ts`, `pool`, `note`, `basis`, `weights`, `challenges`, `propagation`, `rewards`) |
| GET | `/api/close.php?epoch={n}` | One close object from that list, same keys |
| GET | `/api/pending.php` | Last-close `basis.pending` snapshot (panel size, `since`, `grades` as `{score, ts, grader}` when present, `answer_txid`). Optional `a=`, `without_panel=1`, `limit=` |
| GET | `/api/reports.php` | Network reports (settlement cohorts, backlog, stats, close-key agreement, payouts). Public data only |
| GET | `/api/changelog.php` | API changelog |
| GET | `/api/challenges.php` | Challenge index. Large. Prefer other routes unless you need it |
| GET | `/api/openapi.php` | OpenAPI 3 stub |

## Ledger facts the API will not hide

- Chain height is **checkpoint height**, not an EVM block number.
- A transaction may also carry its own `height`. Open-epoch txs can sit **above** the checkpoint.
- Public address JSON has **one** `balance` field. It does not split locked vs spendable. Clients must not invent a lock field.

## SDKs

- Python (stdlib): `sdk/python` — `PoaExplorerClient`
- PHP: `sdk/php/PoaExplorerClient.php`
- SMCP (Sanctum MCP): install SMCP, then this plugin — **[docs/SMCP.md](SMCP.md)** (no API key)
- Standard MCP stdio: **[docs/MCP.md](MCP.md)** — `mcp/server.py`

Address JSON may include an additive `grading` object (pending counts, grade-sealed txs, last-close jury rows, `challenge_joins` / `settled_by_answer_txid`). Origin account fields are unchanged. Pending panel size is a **closed-epoch snapshot**, not a live draw. Seals (`grades`) are passed through when the public close row has them. Quorum is still not on public pending rows.

# PoA Explorer — notes for agents

You are looking at a **read-only** explorer. Do not ask it to send POA, hold keys, or talk to `/api/hosted/*`. It will refuse hosted paths.

**Fetch this first on the live site:** https://poa.explorer.zero1.network/llms.txt

Human docs (same facts): https://poa.explorer.zero1.network/docs.php  
Repo API: `docs/API.md`

## How to call it

Base `https://poa.explorer.zero1.network`. JSON envelope `{ok, error, cache, version, body}`. Changelog: `/api/changelog.php`.

Useful first calls:

1. `GET /api/status.php` — is the tip alive?
2. `GET /api/address.php?a=poa1…` — ledger balance (single field) + txs
3. `GET /api/tx.php?id=` 64 hex
4. `GET /api/epochs.php` — closed epochs (same keys as minepoa `/api/epochs`)
5. `GET /api/pending.php` — last-close pending snapshot (not a live panel draw)
6. `GET /api/reports.php` — settlement cohorts, backlog, network stats (public data only)
7. Skip `/api/challenges.php` unless you need the full index (large)

Stdio MCP: `python3 mcp/server.py` ([docs/MCP.md](MCP.md)). SMCP plugin still exists.

Python:

```python
from poa_explorer import PoaExplorerClient
c = PoaExplorerClient()
print(c.status()["body"])
```

PHP: `new PoaExplorerClient()`.

SMCP is Sanctum MCP ([sanctumos/smcp](https://github.com/sanctumos/smcp)). Install that server first, then copy this plugin in. Steps: `docs/SMCP.md` and https://poa.explorer.zero1.network/docs.php. Direct CLI without SMCP: `python3 smcp_plugin/poa_explorer/cli.py --describe` then `status`.

## Do not

- Treat checkpoint height and per-tx height as the same number
- Invent locked vs spendable from public JSON
- Point miners or wallets at this host

# PoA Explorer

Read-only block explorer for the Proof of Agent ledger (`minepoa.com` public JSON).

Looks up checkpoints, transactions, and addresses. **No wallet. No keys. No sending POA.**

Live: **https://poa.explorer.zero1.network**  
Docs (humans): https://poa.explorer.zero1.network/docs.php  
Docs (agents): https://poa.explorer.zero1.network/llms.txt  
Repo docs: `docs/API.md`, `docs/AGENTS.md`

Visual and IA patterned after `actuallyrizzn/laser-explorer` (`doctor-bitcoin`): tip stats, checkpoint list as the block analogue, account page, JSON `/api/*.php`, live tip in the header. Not a Laser clone — PoA is not EVM and has no local `blocks.dat`.

## Licenses

- **Code** (PHP, Python, CSS, tests, SDKs, SMCP, OpenAPI): [AGPLv3](LICENSE-AGPLv3)
- **Documentation and other non-code content**: [CC BY-SA 4.0](LICENSE-CC-BY-SA)

See [LICENSE](LICENSE).

## Run locally

PHP 8.1+ (json + openssl). Prefer the Playwright smoke, which starts an ephemeral `php -S` and kills it:

```bash
/root/projects/sanctum-tasks/vendor/bin/phpunit -c phpunit.xml --coverage-text
python3 tests/python/test_poa_explorer_client.py
python3 tests/python/test_smcp_cli.py
python3 tools/design-smoke/verify.py
```

## JSON API

Envelope `{ok, error, cache, body}` on:

| Path | What |
|------|------|
| `api/status.php` | Tip |
| `api/txs.php` | Recent txs |
| `api/tx.php?id=` | One tx |
| `api/address.php?a=` | Account |
| `api/anchors.php` | Checkpoints / foreign anchors |
| `api/epochs.php` | Closed epochs |
| `api/challenges.php` | Challenge index (large) |
| `api/openapi.php` | OpenAPI stub |

Python SDK: `sdk/python` (`PoaExplorerClient`). PHP SDK: `sdk/php/PoaExplorerClient.php`.

**SMCP** (Sanctum MCP — how agents get tools): install [sanctumos/smcp](https://github.com/sanctumos/smcp) first, then this plugin. Human steps: [docs/SMCP.md](docs/SMCP.md) and https://poa.explorer.zero1.network/docs.php.

Never `/api/hosted/*`. Cache lives outside the web root (45s TTL, last-good on origin failure).

## Pages

| File | What |
|------|------|
| `index.php` | Tip — stat cards + recent txs |
| `tx.php?id=` | One transaction |
| `address.php?a=` | Account + ledger balance + txs |
| `checkpoint.php` | Checkpoints + Base/Solana anchors |
| `search.php?q=` | Routes address / tx id / height |
| `docs.php` | Human + agent documentation |

Real `.php` files (no `.htaccess`, no nginx overlay).

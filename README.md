# PoA Explorer

Read-only block explorer for the Proof of Agent ledger (`minepoa.com` public JSON).

Looks up checkpoints, transactions, and addresses. **No wallet. No keys. No sending POA.**

Visual and IA patterned after `actuallyrizzn/laser-explorer` (`doctor-bitcoin`): tip stats, checkpoint list as the block analogue, account page, JSON `/api/*.php`, live tip in the header. Not a Laser clone — PoA is not EVM and has no local `blocks.dat`.

Intended public hostname (parked until Mark authorizes Ada): `https://poa.explorer.zero1.network`

Until DNS exists this is **local-only**.

## Run locally

PHP 8.1+ (no extra extensions beyond curl/openssl/json). From `public/`:

```bash
php -S 127.0.0.1:8766 -t public
```

Prefer the Playwright smoke, which starts an ephemeral `php -S` and kills it:

```bash
/root/projects/sanctum-tasks/vendor/bin/phpunit -c phpunit.xml --coverage-text
python3 tools/design-smoke/verify.py
```

## Data

Public read-node only:

- `/api/status`
- `/api/explorer/txs`
- `/api/explorer/tx/:id`
- `/api/explorer/address/:addr`
- `/api/anchors`

Never `/api/hosted/*`. Cache lives outside the web root (`cache/`, 45s TTL, last-good on origin 429).

## Pages

| File | What |
|------|------|
| `index.php` | Tip — stat cards + recent txs |
| `tx.php?id=` | One transaction |
| `address.php?a=` | Account + ledger balance + txs |
| `checkpoint.php` | Checkpoints + Base/Solana anchors |
| `search.php?q=` | Routes address / tx id / height |
| `api/status.php` | JSON tip (laser `/api/tip` analogue) |
| `api/tx.php?id=` | JSON transaction |
| `api/address.php?a=` | JSON account |

Real `.php` files (no `.htaccess`, no nginx overlay).

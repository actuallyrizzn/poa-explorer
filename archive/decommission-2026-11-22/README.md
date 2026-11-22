# PoA Explorer decommission archive (2026-11-22)

Last-state snapshot before `poa.explorer.zero1.network` was removed from multihost.

## Contents

| Path | What |
|------|------|
| `api/*.json` | Live dumps via explorer PHP helpers at archive time (status, txs, anchors, …) |
| `cache/` | Runtime cache dir copy if present (`POA_CACHE_DIR` / `/tmp/poa-explorer-cache`) |
| `meta/` | Deployed rev + cache path notes |
| `MANIFEST.txt` | Host-side sizes at archive time |

## Full epochs blob (large)

The Nov 8 minepoa `/api/epochs` backup (~313 MiB) is **not** in git. It ships as GitHub Release asset:

- Release tag: `decommission-archive-2026-11-22`
- Asset: `epochs-full.json.bak.20261108`

Source on multihost at archive time: `/root/backups/poa-epochs-cache-*.bak.20261108`.

Site was: https://poa.explorer.zero1.network  
Upstream ledger: https://minepoa.com  
Tasks: #6405

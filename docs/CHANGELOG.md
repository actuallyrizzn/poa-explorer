# PoA Explorer API changelog

Every JSON envelope includes `version`. Changes are additive.

## 0.3.0 — 2026-10-26

- Envelope adds `version`. `ok`, `error`, `cache`, `body` unchanged.
- `GET /api/changelog.php`
- `GET /api/epochs.php` `body` is the minepoa `/api/epochs` list with the same field names and timestamps.
- `GET /api/close.php?epoch=N` — one of those close objects.
- `GET /api/pending.php` — last-close pending snapshot (panel size, `since`). Grades and quorum are not on the public pending rows; those fields are `null` with a note.
- Address `body.grading` is additive (pending counts, grade-sealed txs, last-close jury rows). Origin account fields unchanged.
- Stdio MCP: `mcp/server.py`

## 0.2.0 — 2026-10-26

Initial public explorer: status, txs, tx, address, anchors, epochs, challenges, OpenAPI, SDKs, SMCP plugin.

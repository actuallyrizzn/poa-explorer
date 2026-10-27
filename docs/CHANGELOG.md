# PoA Explorer API changelog

Every JSON envelope includes `version`. Changes are additive.

## 0.5.1 — 2026-10-27

- Reports SVG charts use inline `stroke` / `width` / `height` so they render even with a cached pre-0.5 stylesheet. Single-series aria labels fixed. `app.css?v=` follows the API version.

## 0.5.0 — 2026-10-27

- Reports HTML: headline deltas vs prior close, three inline SVG charts (last 30 closes), windowed settlement table with drill-down, one-close cohort table (`reports.php?epoch=N`), still-pending birth×close grid. No Chart.js. `GET /api/reports.php` body unchanged.

## 0.4.2 — 2026-10-27

- Derived epochs snapshot (last close, settled index, reports body) is built by CLI cron (`public/cron/refresh-derived.php`), not on a web visit. Address, pending, and Reports only read that snapshot. Missing or stale is labeled.

## 0.4.1 — 2026-10-27

- Address, pending, and Reports no longer decode the full minepoa `/api/epochs` blob (~18MB) on every request. One worker builds a slim derived cache (last close, settled-by-answer index, reports body); other hits read that.
- Epochs fetch is file-locked and allowed 384M for that parse only, so crawlers cannot OOM the shared 128M PHP-FPM pool.

## 0.4.0 — 2026-10-27

- Reports tab + `GET /api/reports.php`: settlement by birth cohort, grading backlog, network stats, close-key agreement, establishment/payouts. Public data only.
- Birth epoch for settled rows is prior-close `pending.since` (`prior_epoch − since`). Still-pending uses this close epoch − `since`. Live challenge-index `epoch` is not used for historical rows.

## 0.3.1 — 2026-10-27

- Pending rows pass through public close `grades` as `[{score, ts, grader}]` when the field exists. Empty list is zero seals. Missing field stays `null` with a note. Quorum is still not on public pending rows.
- Pending rows add `answer_txid` (the 64-hex answer id; it resolves as a challenge transaction).
- Address `grading` joins this account’s challenge txs to last-close pending and to settled rows across closes by that answer txid (`challenge_joins`, `settled_by_answer_txid`).

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

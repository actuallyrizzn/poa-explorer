<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/rate_limit.php';
require_once __DIR__ . '/store.php';

function poa_h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function poa_is_tx_id(string $q): bool
{
    return (bool) preg_match('/^[a-fA-F0-9]{64}$/', $q);
}

function poa_is_address(string $q): bool
{
    return (bool) preg_match('/^poa1[A-Za-z0-9]{20,90}$/', $q);
}

function poa_is_height(string $q): bool
{
    return (bool) preg_match('/^[0-9]{1,12}$/', $q);
}

function poa_format_ts($ts): string
{
    if ($ts === null || $ts === '') {
        return '—';
    }
    $n = (int) $ts;
    if ($n <= 0) {
        return '—';
    }
    return gmdate('Y-m-d H:i:s', $n) . ' UTC';
}

function poa_format_amount($amount): string
{
    if ($amount === null || $amount === '') {
        return '—';
    }
    return (string) $amount;
}

function poa_short_id(string $id, int $keep = 8): string
{
    $id = trim($id);
    if (strlen($id) <= ($keep * 2) + 1) {
        return $id;
    }
    return substr($id, 0, $keep) . '…' . substr($id, -$keep);
}

function poa_wrap(array $extra): array
{
    return $extra + [
        '_error' => null,
        '_cache' => 'miss',
        '_path' => '',
        '_status' => 0,
        'body' => null,
    ];
}

function poa_bump_memory(string $limit = POA_EXPLORER_EPOCHS_MEMORY): void
{
    $cur = ini_get('memory_limit');
    if ($cur === '-1') {
        return;
    }
    $toBytes = static function (string $v): int {
        $v = trim($v);
        if ($v === '-1' || $v === '') {
            return PHP_INT_MAX;
        }
        $n = (int) $v;
        $u = strtoupper(substr($v, -1));
        return match ($u) {
            'G' => $n * 1024 * 1024 * 1024,
            'M' => $n * 1024 * 1024,
            'K' => $n * 1024,
            default => (int) $v,
        };
    };
    if ($toBytes($cur) < $toBytes($limit)) {
        @ini_set('memory_limit', $limit);
    }
}

function poa_with_file_lock(string $lockFile, callable $fn)
{
    $dir = dirname($lockFile);
    if (!is_dir($dir)) {
        mkdir($dir, 0770, true);
    }
    $fh = fopen($lockFile, 'c');
    if ($fh === false) {
        return $fn();
    }
    flock($fh, LOCK_EX);
    try {
        return $fn();
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

function poa_json_get(string $path, ?int $ttl = null): array
{
    $path = '/' . ltrim($path, '/');
    // Lean rework (0.7): never fetch /api/epochs on the FPM request path.
    // Full-blob fetch + lock-epochs + memory bump lived here — commented, not deleted
    // (Mark: recover for dedicated-box / phase-two paid heavy access).
    //
    // if ($path === '/api/epochs') {
    //     static $epochsLockHeld = false;
    //     if (!$epochsLockHeld) {
    //         $epochsLockHeld = true;
    //         try {
    //             return poa_with_file_lock(poa_cache_dir() . '/lock-epochs', static function () use ($path, $ttl) {
    //                 poa_bump_memory();
    //                 return poa_json_get($path, $ttl);
    //             });
    //         } finally {
    //             $epochsLockHeld = false;
    //         }
    //     }
    //     poa_bump_memory();
    // }
    if ($path === '/api/epochs') {
        return poa_wrap([
            '_error' => 'full /api/epochs fetch disabled on this host; use the slim store / windowed /api/epochs.php',
            '_path' => $path,
            '_status' => 0,
        ]);
    }
    if (str_starts_with($path, '/api/hosted')) {
        return poa_wrap([
            '_error' => 'hosted API is out of scope for this explorer',
            '_path' => $path,
        ]);
    }

    $cacheDir = poa_cache_dir();
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0770, true);
    }
    $ttl = $ttl ?? POA_EXPLORER_CACHE_TTL;
    $cacheFile = $cacheDir . '/' . hash('sha256', $path) . '.json';
    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
        $cached = file_get_contents($cacheFile);
        if ($cached !== false && $cached !== '') {
            $decoded = json_decode($cached, true);
            if (array_key_exists('body', (array) $decoded)) {
                $decoded['_cache'] = 'hit';
                return $decoded;
            }
        }
    }

    $url = poa_origin() . $path;
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => POA_EXPLORER_TIMEOUT,
            'header' => "User-Agent: " . POA_EXPLORER_UA . "\r\nAccept: application/json\r\n",
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    $status = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $status = (int) $m[1];
    }

    $stale = static function () use ($cacheFile, $path, $status): array {
        if (is_file($cacheFile)) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached) && array_key_exists('body', $cached)) {
                $cached['_cache'] = 'stale';
                $cached['_error'] = $cached['_error'] ?? 'origin unreachable; serving last-good cache';
                $cached['_status'] = $status;
                return $cached;
            }
        }
        return poa_wrap([
            '_error' => 'origin unreachable',
            '_status' => $status,
            '_path' => $path,
        ]);
    };

    if ($raw === false || $raw === '') {
        return $stale();
    }

    $decoded = json_decode($raw, true);
    $raw = null;
    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
        return poa_wrap([
            '_error' => 'origin returned non-JSON',
            '_status' => $status,
            '_path' => $path,
        ]);
    }

    $err = null;
    if ($status >= 400) {
        $err = is_array($decoded) && isset($decoded['error'])
            ? (string) $decoded['error']
            : 'origin HTTP ' . $status;
    }

    $out = poa_wrap([
        '_error' => $err,
        '_cache' => 'fresh',
        '_path' => $path,
        '_status' => $status,
        'body' => $decoded,
    ]);
    // Cache denylist: never persist huge origin payloads (epochs already blocked above).
    if ($err === null) {
        $encoded = json_encode($out, JSON_UNESCAPED_SLASHES);
        if (is_string($encoded) && strlen($encoded) <= POA_EXPLORER_ORIGIN_CACHE_MAX_BYTES) {
            @file_put_contents($cacheFile, $encoded);
        }
    }
    return $out;
}

function poa_url_json(string $url, int $ttl): array
{
    $url = trim($url);
    if ($url === '' || !poa_url_is_allowed($url)) {
        return poa_wrap(['_error' => 'refused non-https url', '_path' => $url]);
    }
    $cacheDir = poa_cache_dir();
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0770, true);
    }
    $cacheFile = $cacheDir . '/url-' . hash('sha256', $url) . '.json';
    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
        $cached = json_decode((string) file_get_contents($cacheFile), true);
        if (is_array($cached) && array_key_exists('body', $cached)) {
            $cached['_cache'] = 'hit';
            return $cached;
        }
    }
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => POA_EXPLORER_TIMEOUT,
            'header' => "User-Agent: " . POA_EXPLORER_UA . "\r\nAccept: application/json\r\n",
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    $status = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $status = (int) $m[1];
    }
    if ($raw === false || $raw === '') {
        if (is_file($cacheFile)) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached) && array_key_exists('body', $cached)) {
                $cached['_cache'] = 'stale';
                $cached['_error'] = $cached['_error'] ?? 'origin unreachable; serving last-good cache';
                $cached['_status'] = $status;
                return $cached;
            }
        }
        return poa_wrap(['_error' => 'origin unreachable', '_status' => $status, '_path' => $url]);
    }
    $decoded = json_decode($raw, true);
    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
        return poa_wrap(['_error' => 'origin returned non-JSON', '_status' => $status, '_path' => $url]);
    }
    $err = null;
    if ($status >= 400) {
        $err = is_array($decoded) && isset($decoded['error'])
            ? (string) $decoded['error']
            : 'origin HTTP ' . $status;
    }
    $out = poa_wrap([
        '_error' => $err,
        '_cache' => 'fresh',
        '_path' => $url,
        '_status' => $status,
        'body' => $decoded,
    ]);
    if ($err === null) {
        @file_put_contents($cacheFile, json_encode($out, JSON_UNESCAPED_SLASHES));
    }
    return $out;
}

function poa_status(): array
{
    $row = poa_json_get('/api/status');
    $chain = poa_json_get('/chain/poa-mainnet-1/api/status');
    $body = is_array($row['body']) ? $row['body'] : [];
    $chainBody = is_array($chain['body']) ? $chain['body'] : [];
    if (isset($chainBody['chain_id'])) {
        $body['chain_id'] = $chainBody['chain_id'];
    }
    if (isset($chainBody['checkpoint']) && is_array($chainBody['checkpoint'])) {
        $body['checkpoint'] = $chainBody['checkpoint'];
    }
    $row['body'] = $body;
    if ($row['_error'] && !$chain['_error'] && $body) {
        $row['_error'] = null;
        $row['_cache'] = $chain['_cache'];
    }
    return $row;
}

function poa_json_payload(array $row): array
{
    $err = $row['_error'] ?? null;
    if ($err === '') {
        $err = null;
    }
    return [
        'ok' => $err === null,
        'error' => $err,
        'cache' => $row['_cache'] ?? null,
        'version' => POA_EXPLORER_API_VERSION,
        'body' => $row['body'] ?? null,
    ];
}

function poa_json_out(array $row, int $errorHttp = 400): void
{
    $payload = poa_json_payload($row);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    header('Access-Control-Allow-Origin: *');
    http_response_code($payload['ok'] ? 200 : $errorHttp);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
}

function poa_recent_txs_row(): array
{
    $row = poa_json_get('/api/explorer/txs');
    $body = $row['body'];
    $list = [];
    if (is_array($body) && isset($body[0]) && is_array($body[0])) {
        $list = array_slice($body, 0, POA_EXPLORER_RECENT_TX_LIMIT);
    } elseif (is_array($body) && isset($body['valid']) && is_array($body['valid'])) {
        $list = array_slice($body['valid'], 0, POA_EXPLORER_RECENT_TX_LIMIT);
    }
    $row['body'] = $list;
    return $row;
}

function poa_recent_txs(): array
{
    $body = poa_recent_txs_row()['body'];
    return is_array($body) ? $body : [];
}

function poa_tx(string $id): array
{
    if (!poa_is_tx_id($id)) {
        return poa_wrap(['_error' => 'not a transaction id']);
    }
    return poa_json_get('/api/explorer/tx/' . strtolower($id));
}

function poa_address(string $addr): array
{
    if (!poa_is_address($addr)) {
        return poa_wrap(['_error' => 'not a PoA address']);
    }
    $row = poa_json_get('/api/explorer/address/' . $addr);
    if (is_array($row['body']) && !isset($row['body']['error'])) {
        poa_derived_row();
        $close = poa_derived_last_close();
        $row['body']['grading'] = poa_grading_for_address(
            $addr,
            $row['body'],
            $close,
            poa_challenges_body(),
            null,
            poa_derived_settled_index()
        );
    }
    return $row;
}

function poa_anchors_row(): array
{
    $row = poa_json_get('/api/anchors');
    $body = $row['body'];
    if (!is_array($body) || !isset($body[0]) || !is_array($body[0])) {
        $row['body'] = [];
        return $row;
    }
    usort($body, static function ($a, $b) {
        return ((int) ($b['height'] ?? 0)) <=> ((int) ($a['height'] ?? 0));
    });
    $row['body'] = array_slice($body, 0, POA_EXPLORER_ANCHOR_LIMIT);
    return $row;
}

function poa_anchors(): array
{
    $body = poa_anchors_row()['body'];
    return is_array($body) ? $body : [];
}

/**
 * Windowed epochs for the public API (slim store). Not a full minepoa mirror.
 * Legacy full fetch: poa_json_get('/api/epochs') — disabled; see comment there.
 */
function poa_epochs(): array
{
    if (!poa_store_present()) {
        return poa_wrap([
            '_error' => 'Epoch slim store is not on disk yet. CLI cron builds it; this path does not fetch full /api/epochs.',
            '_cache' => 'miss',
            '_status' => 0,
            'body' => null,
        ]);
    }
    $window = poa_store_window(POA_EXPLORER_EPOCHS_WINDOW);
    return poa_wrap([
        '_error' => null,
        '_cache' => poa_derived_fresh() ? 'hit' : 'stale',
        '_status' => 200,
        '_path' => '/api/epochs',
        'body' => $window,
        '_note' => 'windowed last ' . POA_EXPLORER_EPOCHS_WINDOW . ' slim closes; full history on minepoa.com/api/epochs',
    ]);
}

function poa_epochs_catalog(): array
{
    if (!poa_store_present()) {
        return poa_wrap([
            '_error' => 'Epoch slim store is not on disk yet.',
            '_cache' => 'miss',
            '_status' => 0,
            'body' => null,
        ]);
    }
    return poa_wrap([
        '_error' => null,
        '_cache' => poa_derived_fresh() ? 'hit' : 'stale',
        '_status' => 200,
        'body' => [
            'mode' => 'catalog',
            'closes' => poa_store_catalog(500),
            'window' => POA_EXPLORER_EPOCHS_WINDOW,
        ],
    ]);
}

function poa_challenges(): array
{
    return poa_json_get('/api/challenge-index', POA_EXPLORER_SLOW_CACHE_TTL);
}

function poa_tx_list_from_address(array $addrBody): array
{
    $txs = $addrBody['txs'] ?? [];
    return is_array($txs) ? $txs : [];
}

function poa_finality_label(array $row): string
{
    $final = $row['finality']['final'] ?? $row['final'] ?? null;
    $isFinal = $final === true || $final === 1 || $final === '1';
    if ($isFinal) {
        $via = $row['finality']['via'] ?? null;
        $h = $row['finality']['height'] ?? $row['checkpointed']['height'] ?? null;
        if ($via && $h) {
            return 'final via ' . $via . ' @ ' . $h;
        }
        return 'final';
    }
    return 'unfinal';
}

function poa_changelog(): array
{
    return [
        [
            'version' => '0.7.0',
            'date' => '2026-11-08',
            'changes' => [
                'Lean rework (PRD #1676): CLI stream-strips /api/epochs into SQLite; PHP never json_decodes the full blob on FPM or cron.',
                'GET /api/epochs.php is windowed (last 30 slim closes) or ?mode=catalog; /api/close.php reads SQLite by epoch. Full-blob FPM path commented out, not deleted.',
                'Tip-gated heavy cron + separate refresh-light.php for health/market. memory_limit 192M hard, no bump. Health HTML/API read derived file only — no lock-across-I/O.',
                'Cache denylist for large origin payloads; dir cap + GC. Rate limits from 0.6.1 unchanged.',
            ],
        ],
        [
            'version' => '0.6.1',
            'date' => '2026-11-07',
            'changes' => [
                'App rate limits on expensive paths (per IP, uniform — bots/agents are intended users): HTML tx/address/search 12/min, health 6/min, API tx/address 60/min. Over → 429 + Retry-After. Counter flock is short — never held across origin I/O.',
                'robots.txt Allows / for all user-agents (no path or UA Disallow). Protection is rate limits, not robots exclusion.',
                'Homepage health banner is cache-only (no lock-health / origin poll from every HTML page).',
            ],
        ],
        [
            'version' => '0.6.0',
            'date' => '2026-10-28',
            'changes' => [
                'Health probes: homepage banner + /health.php + GET /api/health.php. Read-only checks of status, tx lookup, epochs snapshot, anchors, settlement freshness, seals, swap reads, and publicly readable swap-write state. Overall OK/Partial/Down with down-since times.',
                'OTC book: /market.php + GET /api/market.php. poaswap.io posted intents (not an AMM). Per-pair best offer and last trade, POA/SOL primary, implied USD from a cached CoinGecko SOL/ETH rate. History stores best-offer and last-trade as separate series. Copy never treats this as a live market price.',
            ],
        ],
        [
            'version' => '0.5.1',
            'date' => '2026-10-27',
            'changes' => [
                'Reports charts draw with inline stroke and size so they stay visible if an old stylesheet is cached. Single-series chart labels no longer read “and over closes.” CSS is cache-busted by API version.',
            ],
        ],
        [
            'version' => '0.5.0',
            'date' => '2026-10-27',
            'changes' => [
                'Reports UI: headline deltas, inline SVG trends (last 30 closes), one-close cohort drill-down (?epoch=), still-pending birth×close grid. No Chart.js. JSON /api/reports.php body unchanged.',
            ],
        ],
        [
            'version' => '0.4.2',
            'date' => '2026-10-27',
            'changes' => [
                'Derived epochs snapshot (last close, settled index, reports body) is built by CLI cron, not on visit. Address, pending, and Reports only read that snapshot. Missing or stale is labeled.',
            ],
        ],
        [
            'version' => '0.4.1',
            'date' => '2026-10-27',
            'changes' => [
                'Address, pending, and Reports no longer decode the full 18MB /api/epochs blob on every hit. One worker builds a slim derived cache (last close, settled index, reports body); other pages read that.',
                'Epochs fetch is file-locked and allowed 384M for that parse only, so a crawler storm cannot OOM the shared 128M PHP-FPM pool.',
            ],
        ],
        [
            'version' => '0.4.0',
            'date' => '2026-10-27',
            'changes' => [
                'Reports tab + GET /api/reports.php: settlement by birth cohort, network-wide grading backlog, stats strip, close-key agreement, establishment/payouts. Public data only.',
                'Birth epoch for settled rows is prior-close pending.since (prior_epoch − since). Still-pending uses this close epoch − since. Live challenge-index epoch is not used for historical rows.',
            ],
        ],
        [
            'version' => '0.3.1',
            'date' => '2026-10-27',
            'changes' => [
                'Pending rows pass through public close grades as [{score, ts, grader}] when the field exists. Empty list means zero seals in that snapshot. Missing field stays null with a note. Quorum is still not on public pending rows.',
                'Pending rows add answer_txid (the 64-hex answer id, which resolves as a challenge tx).',
                'Address grading joins this account’s challenge txs to last-close pending and to settled rows across closes by that answer txid.',
            ],
        ],
        [
            'version' => '0.3.0',
            'date' => '2026-10-26',
            'changes' => [
                'Envelope adds version (additive). Existing ok/error/cache/body unchanged.',
                'GET /api/changelog.php — this list.',
                'GET /api/epochs.php body is the minepoa /api/epochs close list with the same field names and timestamps (epoch, ts, pool, note, basis, weights, challenges, propagation, rewards).',
                'GET /api/close.php?epoch=N — one of those close objects, same keys.',
                'GET /api/pending.php — last-close basis.pending joined to challenge-index authors. Panel size and since (age). Grades and quorum are not on the public pending snapshot; those columns stay empty and labeled.',
                'Address JSON may include additive grading (pending counts, grade-sealed txs, last-close jury/grader rows). Origin account fields unchanged.',
                'Stdio MCP server: mcp/server.py (JSON-RPC, no extra packages). SMCP plugin still ships.',
            ],
        ],
        [
            'version' => '0.2.0',
            'date' => '2026-10-26',
            'changes' => [
                'Initial public explorer: status, txs, tx, address, anchors, epochs, challenges, OpenAPI, Python/PHP SDKs, SMCP plugin.',
            ],
        ],
    ];
}

function poa_changelog_row(): array
{
    return poa_wrap([
        '_cache' => 'fresh',
        'body' => [
            'current' => POA_EXPLORER_API_VERSION,
            'additive' => true,
            'entries' => poa_changelog(),
        ],
    ]);
}

function poa_challenges_body(): ?array
{
    $row = poa_challenges();
    return is_array($row['body']) ? $row['body'] : null;
}

function poa_latest_close(array $epochsBody): ?array
{
    $best = null;
    $bestN = -1;
    foreach ($epochsBody as $row) {
        if (!is_array($row) || !array_key_exists('epoch', $row)) {
            continue;
        }
        $n = (int) $row['epoch'];
        if ($n >= $bestN) {
            $bestN = $n;
            $best = $row;
        }
    }
    return $best;
}

function poa_latest_close_from_origin(): ?array
{
    // Prefer slim store — never pull full origin epochs on this path.
    $fromStore = poa_store_latest_close();
    if (is_array($fromStore)) {
        return $fromStore;
    }
    return poa_derived_last_close();
}

function poa_close_by_epoch($epoch): array
{
    if ($epoch === '' || $epoch === null || !preg_match('/^[0-9]{1,8}$/', (string) $epoch)) {
        return poa_wrap(['_error' => 'pass epoch as a non-negative integer']);
    }
    $n = (int) $epoch;
    // Lean rework: keyed SQLite lookup. Old path scanned poa_epochs() full blob — commented:
    // $row = poa_epochs(); foreach ((array)$row['body'] as $close) { ... }
    $found = poa_store_close_by_epoch($n);
    if ($found === null) {
        return poa_wrap([
            '_error' => 'no close record for that epoch in slim store',
            '_cache' => poa_store_present() ? 'miss' : 'miss',
            '_status' => 0,
        ]);
    }
    return poa_wrap([
        '_error' => null,
        '_cache' => poa_derived_fresh() ? 'hit' : 'stale',
        '_status' => 200,
        '_path' => '/api/epochs',
        'body' => $found,
    ]);
}

function poa_close_keys_present(array $close): array
{
    $missing = [];
    foreach (POA_CLOSE_RECORD_KEYS as $k) {
        if (!array_key_exists($k, $close)) {
            $missing[] = $k;
        }
    }
    return $missing;
}

function poa_pending_grade_items($raw): array
{
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    foreach ($raw as $g) {
        if (!is_array($g)) {
            continue;
        }
        $item = [];
        if (array_key_exists('score', $g)) {
            $item['score'] = is_numeric($g['score']) ? (int) $g['score'] : $g['score'];
        }
        if (array_key_exists('ts', $g)) {
            $item['ts'] = is_numeric($g['ts']) ? (int) $g['ts'] : $g['ts'];
        }
        $grader = trim((string) ($g['grader'] ?? ''));
        if ($grader !== '') {
            $item['grader'] = $grader;
        }
        if ($item !== []) {
            $out[] = $item;
        }
    }
    return $out;
}

function poa_pending_grades_field(array $p): array
{
    if (!array_key_exists('grades', $p)) {
        return [
            'grades' => null,
            'grades_count' => null,
            'grades_note' => 'this pending row has no grades field in the public close',
        ];
    }
    $items = poa_pending_grade_items($p['grades']);
    return [
        'grades' => $items,
        'grades_count' => count($items),
        'grades_note' => null,
    ];
}

function poa_is_challenge_tx(array $tx): bool
{
    return str_contains(strtolower((string) ($tx['type'] ?? '')), 'challenge');
}

function poa_pending_snapshot(?array $close, ?array $challenges): array
{
    $pending = is_array($close) ? ($close['basis']['pending'] ?? []) : [];
    $settled = is_array($close) ? ($close['basis']['settled'] ?? []) : [];
    $answers = is_array($challenges) ? ($challenges['answers'] ?? []) : [];
    if (!is_array($pending)) {
        $pending = [];
    }
    if (!is_array($settled)) {
        $settled = [];
    }
    if (!is_array($answers)) {
        $answers = [];
    }

    $rows = [];
    $with = 0;
    $without = 0;
    $withSeals = 0;
    $zeroSeals = 0;
    $missingGrades = 0;
    foreach ($pending as $aid => $p) {
        if (!is_array($p)) {
            continue;
        }
        $panel = $p['panel'] ?? [];
        $panelList = is_array($panel) ? array_values($panel) : [];
        $size = $panelList !== []
            ? count($panelList)
            : (isset($p['panel_size']) ? (int) $p['panel_size'] : 0);
        if ($size === 0) {
            $without++;
        } else {
            $with++;
        }
        $ans = is_array($answers[(string) $aid] ?? null) ? $answers[(string) $aid] : [];
        $since = array_key_exists('since', $p) && $p['since'] !== null && $p['since'] !== ''
            ? (int) $p['since']
            : null;
        $aidStr = (string) $aid;
        $gradeField = poa_pending_grades_field($p);
        if ($gradeField['grades'] === null) {
            $missingGrades++;
        } elseif ((int) $gradeField['grades_count'] === 0) {
            $zeroSeals++;
        } else {
            $withSeals++;
        }
        $rows[] = [
            'answer' => $aidStr,
            'answer_txid' => poa_is_tx_id($aidStr) ? strtolower($aidStr) : null,
            'author' => (string) ($ans['author'] ?? ''),
            'answer_epoch' => $ans['epoch'] ?? null,
            'source' => $ans['source'] ?? null,
            'kind' => $p['kind'] ?? null,
            'panel' => $panelList,
            'panel_size' => $size,
            'grades' => $gradeField['grades'],
            'grades_count' => $gradeField['grades_count'],
            'grades_note' => $gradeField['grades_note'],
            'quorum' => null,
            'quorum_note' => 'quorum is not on public close pending rows',
            'since' => $since,
            'expiry_epochs' => POA_EXPLORER_EPOCH_EXPIRY,
            'originalPassed' => $p['originalPassed'] ?? null,
        ];
    }

    usort($rows, static function ($a, $b) {
        $rank = static function ($r): int {
            if (($r['grades'] ?? null) === null) {
                return 2;
            }
            if ((int) ($r['grades_count'] ?? 0) === 0) {
                return 1;
            }
            return 0;
        };
        return $rank($a) <=> $rank($b);
    });

    return [
        'as_of_epoch' => is_array($close) ? ($close['epoch'] ?? null) : null,
        'as_of_ts' => is_array($close) ? ($close['ts'] ?? null) : null,
        'source' => 'minepoa GET /api/epochs basis.pending (latest close); authors from GET /api/challenge-index answers',
        'live_panel_note' => 'Panel size is the last closed epoch snapshot, not a live draw. Public assignment routes are not available. Listing order is explorer-side: rows with seals first, then zero-seal rows, then rows with no grades field.',
        'total' => count($rows),
        'with_panel' => $with,
        'without_panel' => $without,
        'with_seals' => $withSeals,
        'zero_seals' => $zeroSeals,
        'missing_grades_field' => $missingGrades,
        'settled_this_close' => count($settled),
        'rows' => $rows,
    ];
}

function poa_filter_pending_snapshot(array $snap, string $addr, bool $withoutOnly, int $limit, array $extraAnswerIds = []): array
{
    $limit = max(1, min(200, $limit));
    $rows = is_array($snap['rows'] ?? null) ? $snap['rows'] : [];
    if ($addr !== '') {
        $ids = [];
        foreach ($extraAnswerIds as $id) {
            $id = strtolower((string) $id);
            if ($id !== '') {
                $ids[$id] = true;
            }
        }
        $rows = array_values(array_filter($rows, static function ($r) use ($addr, $ids) {
            if (!is_array($r)) {
                return false;
            }
            if (strcasecmp((string) ($r['author'] ?? ''), $addr) === 0) {
                return true;
            }
            $aid = strtolower((string) ($r['answer'] ?? ''));
            return $aid !== '' && isset($ids[$aid]);
        }));
    }
    if ($withoutOnly) {
        $rows = array_values(array_filter($rows, static function ($r) {
            return is_array($r) && (int) ($r['panel_size'] ?? 0) === 0;
        }));
    }
    $matched = count($rows);
    $shown = array_slice($rows, 0, $limit);
    $out = $snap;
    $out['filter_address'] = $addr !== '' ? $addr : null;
    $out['filter_without_panel'] = $withoutOnly;
    $out['matched'] = $matched;
    $out['shown'] = count($shown);
    $out['limit'] = $limit;
    $out['rows'] = $shown;
    return $out;
}

function poa_pending_row(string $addr = '', bool $withoutOnly = false, int $limit = POA_EXPLORER_PENDING_PAGE_LIMIT): array
{
    $derived = poa_derived_row();
    $ch = poa_challenges();
    $close = poa_derived_last_close();
    $snap = poa_pending_snapshot($close, is_array($ch['body']) ? $ch['body'] : null);
    $extraIds = [];
    if ($addr !== '' && poa_is_address($addr)) {
        $acct = poa_json_get('/api/explorer/address/' . $addr);
        if (is_array($acct['body']) && !isset($acct['body']['error'])) {
            $extraIds = array_keys(poa_challenge_txs_by_id($acct['body']));
        }
    }
    $body = poa_filter_pending_snapshot($snap, $addr, $withoutOnly, $limit, $extraIds);
    $err = $derived['_error'] ?? null;
    if ($err === null && ($ch['_error'] ?? null)) {
        $err = $ch['_error'];
    }
    return [
        '_error' => $err,
        '_cache' => $derived['_cache'] ?? 'miss',
        '_path' => '/api/epochs',
        '_status' => $derived['_status'] ?? 0,
        'body' => $body,
    ];
}

function poa_is_grade_tx(array $tx): bool
{
    $t = strtolower((string) ($tx['type'] ?? ''));
    return str_contains($t, 'grade');
}

function poa_challenge_txs_by_id(array $addrBody): array
{
    $out = [];
    foreach (poa_tx_list_from_address($addrBody) as $tx) {
        if (!is_array($tx) || !poa_is_challenge_tx($tx)) {
            continue;
        }
        $id = strtolower((string) ($tx['id'] ?? ''));
        if (!poa_is_tx_id($id)) {
            continue;
        }
        $out[$id] = $tx;
    }
    return $out;
}

function poa_compact_pending_row(array $r): array
{
    return [
        'answer' => $r['answer'] ?? null,
        'answer_txid' => $r['answer_txid'] ?? null,
        'since' => $r['since'] ?? null,
        'kind' => $r['kind'] ?? null,
        'panel' => $r['panel'] ?? [],
        'panel_size' => $r['panel_size'] ?? 0,
        'grades' => $r['grades'] ?? null,
        'grades_count' => $r['grades_count'] ?? null,
    ];
}

function poa_settled_index_from_epochs(?array $epochsBody): array
{
    $idx = [];
    if (!is_array($epochsBody)) {
        return $idx;
    }
    foreach ($epochsBody as $c) {
        if (!is_array($c)) {
            continue;
        }
        $settled = $c['basis']['settled'] ?? [];
        if (!is_array($settled)) {
            continue;
        }
        foreach ($settled as $s) {
            if (!is_array($s)) {
                continue;
            }
            $aid = strtolower((string) ($s['answer'] ?? ''));
            if ($aid === '') {
                continue;
            }
            $idx[$aid][] = [
                'answer_txid' => $aid,
                'close_epoch' => $c['epoch'] ?? null,
                'close_ts' => $c['ts'] ?? null,
                'from' => $s['from'] ?? null,
                'passed' => $s['passed'] ?? null,
            ];
        }
    }
    return $idx;
}

function poa_settled_by_answer_txid(?array $close, ?array $epochsBody, array $txIds, ?array $settledIndex = null): array
{
    // Prefer SQLite keyed lookups (full history, no 27 MiB JSON load).
    // Skip when caller supplied epochsBody/settledIndex (unit tests / explicit overrides).
    if (
        poa_store_present()
        && ($settledIndex === null || $settledIndex === [])
        && $epochsBody === null
    ) {
        return poa_store_settled_for_answers($txIds);
    }
    if (is_array($settledIndex)) {
        $hits = [];
        foreach ($txIds as $aid => $_) {
            $aid = strtolower((string) $aid);
            if ($aid === '' || !isset($settledIndex[$aid]) || !is_array($settledIndex[$aid])) {
                continue;
            }
            foreach ($settledIndex[$aid] as $hit) {
                if (is_array($hit)) {
                    $hits[] = $hit;
                }
            }
        }
        return $hits;
    }
    $closes = [];
    if (is_array($epochsBody) && $epochsBody) {
        foreach ($epochsBody as $c) {
            if (is_array($c)) {
                $closes[] = $c;
            }
        }
    } elseif (is_array($close)) {
        $closes[] = $close;
    }
    return poa_settled_by_answer_txid($close, null, $txIds, poa_settled_index_from_epochs($closes));
}

function poa_grading_for_address(string $addr, array $addrBody, ?array $close, ?array $challenges, ?array $epochsBody = null, ?array $settledIndex = null): array
{
    $snap = poa_pending_snapshot($close, $challenges);
    $challengeTxs = poa_challenge_txs_by_id($addrBody);
    $pendingByAnswer = [];
    foreach ($snap['rows'] as $r) {
        if (!is_array($r)) {
            continue;
        }
        $aid = strtolower((string) ($r['answer'] ?? ''));
        if ($aid !== '') {
            $pendingByAnswer[$aid] = $r;
        }
    }

    $pendingMine = [];
    $seen = [];
    $without = 0;
    foreach ($snap['rows'] as $r) {
        if (!is_array($r)) {
            continue;
        }
        $aid = strtolower((string) ($r['answer'] ?? ''));
        $authorMatch = strcasecmp((string) ($r['author'] ?? ''), $addr) === 0;
        $txidMatch = $aid !== '' && isset($challengeTxs[$aid]);
        if (!$authorMatch && !$txidMatch) {
            continue;
        }
        if ($aid !== '' && isset($seen[$aid])) {
            continue;
        }
        if ($aid !== '') {
            $seen[$aid] = true;
        }
        $pendingMine[] = $r;
        if ((int) ($r['panel_size'] ?? 0) === 0) {
            $without++;
        }
    }

    $answersMine = [];
    $answers = is_array($challenges) ? ($challenges['answers'] ?? []) : [];
    if (is_array($answers)) {
        foreach ($answers as $aid => $ans) {
            if (!is_array($ans)) {
                continue;
            }
            if (strcasecmp((string) ($ans['author'] ?? ''), $addr) !== 0) {
                continue;
            }
            $answersMine[] = [
                'answer' => (string) $aid,
                'answer_txid' => poa_is_tx_id((string) $aid) ? strtolower((string) $aid) : null,
                'epoch' => $ans['epoch'] ?? null,
                'source' => $ans['source'] ?? null,
            ];
        }
    }

    $settledMine = [];
    $juryMine = [];
    $settled = is_array($close) ? ($close['basis']['settled'] ?? []) : [];
    if (is_array($settled)) {
        foreach ($settled as $s) {
            if (!is_array($s)) {
                continue;
            }
            $sid = strtolower((string) ($s['answer'] ?? ''));
            $row = $s;
            $row['answer_txid'] = poa_is_tx_id($sid) ? $sid : null;
            if (strcasecmp((string) ($s['from'] ?? ''), $addr) === 0) {
                $settledMine[] = $row;
            }
            foreach ((array) ($s['jury'] ?? []) as $j) {
                if (is_array($j) && strcasecmp((string) ($j['grader'] ?? ''), $addr) === 0) {
                    $juryMine[] = [
                        'answer' => $s['answer'] ?? null,
                        'answer_txid' => poa_is_tx_id($sid) ? $sid : null,
                        'from' => $s['from'] ?? null,
                        'passed' => $s['passed'] ?? null,
                        'aligned' => $j['aligned'] ?? null,
                    ];
                }
            }
        }
    }

    $served = null;
    $graders = is_array($close) ? ($close['basis']['graders_served'] ?? []) : [];
    if (is_array($graders)) {
        foreach ($graders as $pair) {
            if (is_array($pair) && strcasecmp((string) ($pair[0] ?? ''), $addr) === 0) {
                $served = $pair[1] ?? null;
                break;
            }
        }
    }

    $gradeTxs = [];
    foreach (poa_tx_list_from_address($addrBody) as $tx) {
        if (is_array($tx) && poa_is_grade_tx($tx)) {
            $gradeTxs[] = $tx;
        }
    }

    $settledByTxid = poa_settled_by_answer_txid($close, $epochsBody, $challengeTxs, $settledIndex);
    $settledJoinById = [];
    foreach ($settledByTxid as $hit) {
        $settledJoinById[(string) ($hit['answer_txid'] ?? '')] = $hit;
    }

    $challengeJoins = [];
    foreach ($challengeTxs as $id => $tx) {
        $pend = $pendingByAnswer[$id] ?? null;
        $challengeJoins[] = [
            'answer_txid' => $id,
            'tx_epoch' => $tx['epoch'] ?? null,
            'pending' => is_array($pend) ? poa_compact_pending_row($pend) : null,
            'settled' => $settledJoinById[$id] ?? null,
        ];
    }

    return [
        'as_of_epoch' => $snap['as_of_epoch'],
        'as_of_ts' => $snap['as_of_ts'],
        'source' => $snap['source'],
        'live_panel_note' => $snap['live_panel_note'],
        'pending_count' => count($pendingMine),
        'without_panel_count' => $without,
        'answers_as_author_count' => count($answersMine),
        'settled_as_author_this_close' => count($settledMine),
        'settled_by_answer_txid_count' => count($settledByTxid),
        'jury_rows_this_close' => count($juryMine),
        'graders_served_this_close' => $served,
        'grade_sealed_txs' => $gradeTxs,
        'pending' => $pendingMine,
        'answers_as_author' => $answersMine,
        'settled_as_author' => $settledMine,
        'settled_by_answer_txid' => $settledByTxid,
        'challenge_joins' => $challengeJoins,
        'jury_as_grader' => $juryMine,
    ];
}

require_once __DIR__ . '/reports.php';
require_once __DIR__ . '/health.php';
require_once __DIR__ . '/market.php';

function poa_derived_file(string $name): string
{
    return poa_cache_dir() . '/derived-' . $name . '.json';
}

function poa_derived_present(): bool
{
    // reports + last-close required. Settled lives in SQLite (preferred) or legacy settled-index.
    return is_file(poa_derived_file('reports')) && is_file(poa_derived_file('last-close'));
}

function poa_derived_mtime(): ?int
{
    $tStore = poa_store_mtime();
    $f = poa_derived_file('reports');
    $tRep = is_file($f) ? filemtime($f) : false;
    $candidates = array_filter([$tStore, $tRep === false ? null : $tRep], static fn($v) => $v !== null);
    if ($candidates === []) {
        return null;
    }
    return max($candidates);
}

function poa_derived_fresh(): bool
{
    if (!poa_derived_present()) {
        return false;
    }
    $t = poa_derived_mtime();
    if ($t === null) {
        return false;
    }
    return (time() - $t) < POA_EXPLORER_DERIVED_STALE_AFTER;
}

function poa_read_derived_json(string $name): mixed
{
    $f = poa_derived_file($name);
    if (!is_file($f)) {
        return null;
    }
    $decoded = json_decode((string) file_get_contents($f), true);
    return $decoded;
}

function poa_derived_last_close(): ?array
{
    $fromStore = poa_store_latest_close();
    if (is_array($fromStore)) {
        return $fromStore;
    }
    $row = poa_read_derived_json('last-close');
    return is_array($row) ? $row : null;
}

function poa_derived_settled_index(): array
{
    // Avoid loading the legacy 27 MiB JSON when SQLite is present.
    if (poa_store_present()) {
        return [];
    }
    $row = poa_read_derived_json('settled-index');
    return is_array($row) ? $row : [];
}

function poa_derived_reports_body(): ?array
{
    $row = poa_read_derived_json('reports');
    return is_array($row) ? $row : null;
}

function poa_write_derived_from_epochs(
    array $epochsBody,
    array $status,
    array $txs,
    array $anchors
): void {
    $dir = poa_cache_dir();
    if (!is_dir($dir)) {
        mkdir($dir, 0770, true);
    }
    $reports = poa_reports_from_public($epochsBody, $status, $txs, $anchors);
    $last = poa_latest_close($epochsBody);
    @file_put_contents(poa_derived_file('reports'), json_encode($reports, JSON_UNESCAPED_SLASHES));
    @file_put_contents(poa_derived_file('last-close'), json_encode($last, JSON_UNESCAPED_SLASHES));
    // Legacy 27 MiB settled-index JSON write — commented (SQLite settled table replaces it).
    // $settled = poa_settled_index_from_epochs($epochsBody);
    // @file_put_contents(poa_derived_file('settled-index'), json_encode($settled, JSON_UNESCAPED_SLASHES));
}

function poa_prune_origin_cache(int $maxAge = 7200, int $maxDelete = 1500): void
{
    $dir = poa_cache_dir();
    if (!is_dir($dir)) {
        return;
    }
    $n = 0;
    $now = time();
    foreach (scandir($dir) ?: [] as $base) {
        if ($base === '.' || $base === '..') {
            continue;
        }
        if (str_starts_with($base, 'derived-') || str_starts_with($base, 'lock-')) {
            continue;
        }
        $f = $dir . '/' . $base;
        if (!is_file($f)) {
            continue;
        }
        if (($now - filemtime($f)) < $maxAge) {
            continue;
        }
        @unlink($f);
        $n++;
        if ($n >= $maxDelete) {
            break;
        }
    }
}

function poa_derived_row(): array
{
    if (!poa_derived_present()) {
        return [
            '_error' => 'Epoch slim store is not on disk yet. Tip-gated CLI cron builds it; this page does not fetch /api/epochs on visit.',
            '_cache' => 'miss',
            '_status' => 0,
        ];
    }
    return [
        '_error' => null,
        '_cache' => poa_derived_fresh() ? 'hit' : 'stale',
        '_status' => 200,
    ];
}

function poa_stripper_bin(): string
{
    $env = getenv('POA_STRIP_EPOCHS_BIN');
    if (is_string($env) && $env !== '' && is_file($env)) {
        return $env;
    }
    $candidates = [
        dirname(__DIR__, 2) . '/tools/strip_epochs_to_sqlite.py',
        dirname(__DIR__) . '/../tools/strip_epochs_to_sqlite.py',
        '/var/www/poa.explorer.zero1.network/tools/strip_epochs_to_sqlite.py',
        '/var/www/poa.explorer.zero1.network/html/../tools/strip_epochs_to_sqlite.py',
        // multihost git SRC_DIR (Ada sync; public/ alone does not ship tools/)
        '/root/repos/poa.explorer.zero1.network/tools/strip_epochs_to_sqlite.py',
    ];
    foreach ($candidates as $c) {
        if (is_file($c)) {
            return $c;
        }
    }
    return $candidates[0];
}

function poa_cache_bytes_under(string $dir, bool $derivedOnly): int
{
    if (!is_dir($dir)) {
        return 0;
    }
    $n = 0;
    foreach (scandir($dir) ?: [] as $base) {
        if ($base === '.' || $base === '..') {
            continue;
        }
        $f = $dir . '/' . $base;
        if (!is_file($f)) {
            continue;
        }
        $isDerived = str_starts_with($base, 'derived-')
            || str_starts_with($base, 'epochs-slim')
            || str_ends_with($base, '.sqlite')
            || str_ends_with($base, '.sqlite-wal')
            || str_ends_with($base, '.sqlite-shm');
        if ($derivedOnly && !$isDerived) {
            continue;
        }
        if (!$derivedOnly && ($isDerived || str_starts_with($base, 'lock-') || $base === 'ratelimit' || is_dir($f))) {
            continue;
        }
        $n += (int) filesize($f);
    }
    return $n;
}

function poa_cache_gc(): void
{
    $dir = poa_cache_dir();
    if (!is_dir($dir)) {
        return;
    }
    // LRU delete non-derived origin caches until under cap.
    $files = [];
    foreach (scandir($dir) ?: [] as $base) {
        if ($base === '.' || $base === '..') {
            continue;
        }
        if (str_starts_with($base, 'derived-') || str_starts_with($base, 'lock-')
            || str_starts_with($base, 'epochs-slim') || $base === 'ratelimit') {
            continue;
        }
        $f = $dir . '/' . $base;
        if (!is_file($f)) {
            continue;
        }
        $files[] = ['path' => $f, 'mtime' => (int) filemtime($f), 'size' => (int) filesize($f)];
    }
    usort($files, static fn($a, $b) => $a['mtime'] <=> $b['mtime']);
    $total = array_sum(array_column($files, 'size'));
    foreach ($files as $meta) {
        if ($total <= POA_EXPLORER_CACHE_DIR_CAP_BYTES) {
            break;
        }
        @unlink($meta['path']);
        $total -= $meta['size'];
    }
}

/**
 * Tip-gated heavy refresh: stream-strip epochs → SQLite + slim reports/last-close.
 * Does NOT call poa_health_run / market (those are refresh-light.php).
 */
function poa_refresh_derived(bool $force = false): array
{
    if (PHP_SAPI !== 'cli') {
        return poa_wrap(['_error' => 'refresh-derived is CLI only', '_status' => 403]);
    }
    @ini_set('memory_limit', POA_EXPLORER_CLI_MEMORY);
    @set_time_limit(POA_EXPLORER_CLI_WALL_SECONDS);

    return poa_with_file_lock(poa_cache_dir() . '/lock-derived', static function () use ($force): array {
        $t0 = microtime(true);
        $status = poa_status();
        $tipEpoch = null;
        if (is_array($status['body'])) {
            $tipEpoch = $status['body']['epoch'] ?? $status['body']['current_epoch'] ?? $status['body']['epochs_closed'] ?? null;
            if (is_numeric($tipEpoch)) {
                $tipEpoch = (int) $tipEpoch;
            } else {
                $tipEpoch = null;
            }
        }
        $stateFile = poa_derived_file('tip-epoch');
        $prevTip = null;
        if (is_file($stateFile)) {
            $prevRaw = trim((string) file_get_contents($stateFile));
            if ($prevRaw !== '' && is_numeric($prevRaw)) {
                $prevTip = (int) $prevRaw;
            }
        }
        $weeklyForce = false;
        $stampFile = poa_derived_file('last-heavy-refresh');
        if (is_file($stampFile)) {
            $age = time() - (int) filemtime($stampFile);
            if ($age > 7 * 86400) {
                $weeklyForce = true;
            }
        } else {
            $weeklyForce = true;
        }
        if (!$force && $tipEpoch !== null && $prevTip !== null && $tipEpoch === $prevTip && !$weeklyForce) {
            return [
                '_error' => null,
                '_cache' => 'skip',
                '_status' => 200,
                '_note' => 'tip unchanged; heavy ingest skipped',
                'tip_epoch' => $tipEpoch,
            ];
        }

        $dir = poa_cache_dir();
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }
        $raw = $dir . '/epochs-raw.download';
        // Bootstrap: --from-file=/path or POA_EPOCHS_RAW_FILE (e.g. §5 backup while minepoa /api/epochs is paused).
        $fromFile = getenv('POA_EPOCHS_RAW_FILE');
        if (!is_string($fromFile) || $fromFile === '') {
            $fromFile = null;
        }
        if ($fromFile === null && PHP_SAPI === 'cli' && isset($GLOBALS['argv']) && is_array($GLOBALS['argv'])) {
            foreach ($GLOBALS['argv'] as $arg) {
                if (is_string($arg) && str_starts_with($arg, '--from-file=')) {
                    $fromFile = substr($arg, strlen('--from-file='));
                    break;
                }
            }
        }
        $deleteRaw = false;
        if (is_string($fromFile) && $fromFile !== '' && is_file($fromFile)) {
            // Use bootstrap path in place — never copy a 300MiB blob on a 1GB box.
            $raw = $fromFile;
            $deleteRaw = false;
        } else {
            $url = poa_origin() . '/api/epochs';
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 120,
                    'header' => "User-Agent: " . POA_EXPLORER_UA . "\r\nAccept: application/json\r\n",
                    'ignore_errors' => true,
                ],
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
            ]);
            // Stream to disk — never hold the full origin body in PHP.
            $in = @fopen($url, 'r', false, $ctx);
            $httpStatus = 0;
            if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $hm)) {
                $httpStatus = (int) $hm[1];
            }
            if ($in === false) {
                return poa_wrap(['_error' => 'origin /api/epochs download failed', '_status' => $httpStatus]);
            }
            if ($httpStatus >= 400) {
                $snip = stream_get_contents($in, 200) ?: '';
                fclose($in);
                return poa_wrap([
                    '_error' => 'origin /api/epochs HTTP ' . $httpStatus . ': ' . $snip,
                    '_status' => $httpStatus,
                ]);
            }
            $outFh = @fopen($raw, 'w');
            if ($outFh === false) {
                fclose($in);
                return poa_wrap(['_error' => 'could not write epochs temp file', '_status' => 0]);
            }
            stream_copy_to_stream($in, $outFh);
            fclose($in);
            fclose($outFh);
            // Fail-closed on paused origin error object (small file).
            $size = (int) filesize($raw);
            if ($size > 0 && $size < 4096) {
                $probe = json_decode((string) file_get_contents($raw), true);
                if (is_array($probe) && isset($probe['error']) && !array_is_list($probe)) {
                    @unlink($raw);
                    return poa_wrap([
                        '_error' => 'origin /api/epochs paused/error: ' . (string) $probe['error'],
                        '_status' => $httpStatus ?: 503,
                    ]);
                }
            }
            $deleteRaw = true;
        }

        $bin = poa_stripper_bin();
        $sqlite = poa_store_path();
        $jsonl = poa_store_jsonl_path();
        $cmd = 'python3 ' . escapeshellarg($bin)
            . ' ' . escapeshellarg($raw)
            . ' ' . escapeshellarg($sqlite)
            . ' --slim-jsonl ' . escapeshellarg($jsonl)
            . ' 2>&1';
        $out = [];
        $code = 0;
        exec($cmd, $out, $code);
        if ($deleteRaw) {
            @unlink($raw);
        }
        if ($code !== 0) {
            return poa_wrap([
                '_error' => 'stripper failed: ' . implode("\n", $out),
                '_status' => $code,
            ]);
        }

        // Build reports one close at a time so PHP stays under the 192M CLI cap.
        $txs = poa_recent_txs_row();
        $anchors = poa_anchors_row();
        $epochsBody = [];
        $pdo = poa_store_pdo();
        if ($pdo instanceof PDO) {
            foreach ($pdo->query('SELECT json FROM closes ORDER BY epoch ASC') as $row) {
                $decoded = json_decode((string) ($row['json'] ?? ''), true);
                if (is_array($decoded)) {
                    $epochsBody[] = $decoded;
                }
            }
        } else {
            $epochsBody = poa_store_load_slim_closes();
        }
        poa_write_derived_from_epochs(
            $epochsBody,
            is_array($status['body']) ? $status['body'] : [],
            is_array($txs['body']) ? $txs['body'] : [],
            is_array($anchors['body']) ? $anchors['body'] : []
        );
        // Drop the in-memory close list before any further work.
        $closeCount = count($epochsBody);
        unset($epochsBody);
        // Stop writing the 27 MiB settled-index JSON when SQLite has it.
        // Legacy file left in place if present; new runs skip rewrite via empty unlink intent:
        $legacySettled = poa_derived_file('settled-index');
        if (is_file($legacySettled) && poa_store_present()) {
            // Comment-don't-delete: move aside once so GC budget is free.
            @rename($legacySettled, $legacySettled . '.pre-0.7.bak');
        }

        if ($tipEpoch !== null) {
            @file_put_contents($stateFile, (string) $tipEpoch);
        }
        @file_put_contents($stampFile, gmdate('c'));
        poa_prune_origin_cache();
        poa_cache_gc();

        $elapsed = microtime(true) - $t0;
        if ($elapsed > POA_EXPLORER_CLI_WALL_SECONDS) {
            return poa_wrap([
                '_error' => 'wall clock exceeded after strip',
                '_status' => 0,
                'elapsed' => $elapsed,
            ]);
        }
        $err = $status['_error'] ?? null;
        foreach ([$txs, $anchors] as $row) {
            if ($err === null && !empty($row['_error'])) {
                $err = $row['_error'];
            }
        }
        return [
            '_error' => $err,
            '_cache' => 'fresh',
            '_status' => 200,
            'elapsed' => $elapsed,
            'tip_epoch' => $tipEpoch,
            'closes' => $closeCount,
            'stripper' => implode("\n", $out),
        ];
    });
}

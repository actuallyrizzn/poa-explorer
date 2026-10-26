<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

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

function poa_json_get(string $path, ?int $ttl = null): array
{
    $path = '/' . ltrim($path, '/');
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
        $row['body']['grading'] = poa_grading_for_address(
            $addr,
            $row['body'],
            poa_latest_close_from_origin(),
            poa_challenges_body()
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

function poa_epochs(): array
{
    return poa_json_get('/api/epochs', POA_EXPLORER_SLOW_CACHE_TTL);
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
    $row = poa_epochs();
    if (!is_array($row['body'])) {
        return null;
    }
    return poa_latest_close($row['body']);
}

function poa_close_by_epoch($epoch): array
{
    if ($epoch === '' || $epoch === null || !preg_match('/^[0-9]{1,8}$/', (string) $epoch)) {
        return poa_wrap(['_error' => 'pass epoch as a non-negative integer']);
    }
    $n = (int) $epoch;
    $row = poa_epochs();
    if ($row['_error'] && !is_array($row['body'])) {
        return $row;
    }
    $found = null;
    foreach ((array) $row['body'] as $close) {
        if (is_array($close) && (int) ($close['epoch'] ?? -1) === $n) {
            $found = $close;
            break;
        }
    }
    if ($found === null) {
        return poa_wrap([
            '_error' => 'no close record for that epoch',
            '_cache' => $row['_cache'] ?? 'miss',
        ]);
    }
    $row['body'] = $found;
    return $row;
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
    foreach ($pending as $aid => $p) {
        if (!is_array($p)) {
            continue;
        }
        $panel = $p['panel'] ?? [];
        $panelList = is_array($panel) ? array_values($panel) : [];
        $size = count($panelList);
        if ($size === 0) {
            $without++;
        } else {
            $with++;
        }
        $ans = is_array($answers[(string) $aid] ?? null) ? $answers[(string) $aid] : [];
        $since = array_key_exists('since', $p) && $p['since'] !== null && $p['since'] !== ''
            ? (int) $p['since']
            : null;
        $rows[] = [
            'answer' => (string) $aid,
            'author' => (string) ($ans['author'] ?? ''),
            'answer_epoch' => $ans['epoch'] ?? null,
            'source' => $ans['source'] ?? null,
            'kind' => $p['kind'] ?? null,
            'panel' => $panelList,
            'panel_size' => $size,
            'grades' => null,
            'grades_note' => 'not on public close pending rows',
            'quorum' => null,
            'quorum_note' => 'not on public close pending rows',
            'since' => $since,
            'expiry_epochs' => POA_EXPLORER_EPOCH_EXPIRY,
            'originalPassed' => $p['originalPassed'] ?? null,
        ];
    }

    return [
        'as_of_epoch' => is_array($close) ? ($close['epoch'] ?? null) : null,
        'as_of_ts' => is_array($close) ? ($close['ts'] ?? null) : null,
        'source' => 'minepoa GET /api/epochs basis.pending (latest close); authors from GET /api/challenge-index answers',
        'live_panel_note' => 'Panel size is the last closed epoch snapshot, not a live draw. Public assignment routes are not available.',
        'total' => count($rows),
        'with_panel' => $with,
        'without_panel' => $without,
        'settled_this_close' => count($settled),
        'rows' => $rows,
    ];
}

function poa_filter_pending_snapshot(array $snap, string $addr, bool $withoutOnly, int $limit): array
{
    $limit = max(1, min(200, $limit));
    $rows = is_array($snap['rows'] ?? null) ? $snap['rows'] : [];
    if ($addr !== '') {
        $rows = array_values(array_filter($rows, static function ($r) use ($addr) {
            return is_array($r) && strcasecmp((string) ($r['author'] ?? ''), $addr) === 0;
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
    $epochs = poa_epochs();
    $ch = poa_challenges();
    $close = is_array($epochs['body']) ? poa_latest_close($epochs['body']) : null;
    $snap = poa_pending_snapshot($close, is_array($ch['body']) ? $ch['body'] : null);
    $body = poa_filter_pending_snapshot($snap, $addr, $withoutOnly, $limit);
    $err = $epochs['_error'] ?? null;
    if ($err === null && ($ch['_error'] ?? null)) {
        $err = $ch['_error'];
    }
    return [
        '_error' => $err,
        '_cache' => $epochs['_cache'] ?? 'miss',
        '_path' => '/api/epochs',
        '_status' => $epochs['_status'] ?? 0,
        'body' => $body,
    ];
}

function poa_is_grade_tx(array $tx): bool
{
    $t = strtolower((string) ($tx['type'] ?? ''));
    return str_contains($t, 'grade');
}

function poa_grading_for_address(string $addr, array $addrBody, ?array $close, ?array $challenges): array
{
    $snap = poa_pending_snapshot($close, $challenges);
    $pendingMine = [];
    $without = 0;
    foreach ($snap['rows'] as $r) {
        if (strcasecmp((string) ($r['author'] ?? ''), $addr) !== 0) {
            continue;
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
            if (strcasecmp((string) ($s['from'] ?? ''), $addr) === 0) {
                $settledMine[] = $s;
            }
            foreach ((array) ($s['jury'] ?? []) as $j) {
                if (is_array($j) && strcasecmp((string) ($j['grader'] ?? ''), $addr) === 0) {
                    $juryMine[] = [
                        'answer' => $s['answer'] ?? null,
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

    return [
        'as_of_epoch' => $snap['as_of_epoch'],
        'as_of_ts' => $snap['as_of_ts'],
        'source' => $snap['source'],
        'live_panel_note' => $snap['live_panel_note'],
        'pending_count' => count($pendingMine),
        'without_panel_count' => $without,
        'answers_as_author_count' => count($answersMine),
        'settled_as_author_this_close' => count($settledMine),
        'jury_rows_this_close' => count($juryMine),
        'graders_served_this_close' => $served,
        'grade_sealed_txs' => $gradeTxs,
        'pending' => $pendingMine,
        'answers_as_author' => $answersMine,
        'settled_as_author' => $settledMine,
        'jury_as_grader' => $juryMine,
    ];
}

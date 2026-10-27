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
        $epochsRow = poa_epochs();
        $epochsBody = is_array($epochsRow['body']) ? $epochsRow['body'] : null;
        $close = is_array($epochsBody) ? poa_latest_close($epochsBody) : null;
        $row['body']['grading'] = poa_grading_for_address(
            $addr,
            $row['body'],
            $close,
            poa_challenges_body(),
            $epochsBody
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
    $epochs = poa_epochs();
    $ch = poa_challenges();
    $close = is_array($epochs['body']) ? poa_latest_close($epochs['body']) : null;
    $snap = poa_pending_snapshot($close, is_array($ch['body']) ? $ch['body'] : null);
    $extraIds = [];
    if ($addr !== '' && poa_is_address($addr)) {
        $acct = poa_json_get('/api/explorer/address/' . $addr);
        if (is_array($acct['body']) && !isset($acct['body']['error'])) {
            $extraIds = array_keys(poa_challenge_txs_by_id($acct['body']));
        }
    }
    $body = poa_filter_pending_snapshot($snap, $addr, $withoutOnly, $limit, $extraIds);
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

function poa_settled_by_answer_txid(?array $close, ?array $epochsBody, array $txIds): array
{
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
    $hits = [];
    foreach ($closes as $c) {
        $settled = $c['basis']['settled'] ?? [];
        if (!is_array($settled)) {
            continue;
        }
        foreach ($settled as $s) {
            if (!is_array($s)) {
                continue;
            }
            $aid = strtolower((string) ($s['answer'] ?? ''));
            if ($aid === '' || !isset($txIds[$aid])) {
                continue;
            }
            $hits[] = [
                'answer_txid' => $aid,
                'close_epoch' => $c['epoch'] ?? null,
                'close_ts' => $c['ts'] ?? null,
                'from' => $s['from'] ?? null,
                'passed' => $s['passed'] ?? null,
            ];
        }
    }
    return $hits;
}

function poa_grading_for_address(string $addr, array $addrBody, ?array $close, ?array $challenges, ?array $epochsBody = null): array
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

    $settledByTxid = poa_settled_by_answer_txid($close, $epochsBody, $challengeTxs);
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

<?php
declare(strict_types=1);

/**
 * Read-only health probes of feeds this explorer uses.
 * No writes. Swap-write state only from public book fields.
 */

function poa_health_file(string $name): string
{
    return poa_derived_file($name);
}

function poa_health_feed(
    string $id,
    string $label,
    string $status,
    ?string $note = null,
    ?int $http = null,
    ?int $since = null
): array {
    if (!in_array($status, ['ok', 'degraded', 'down'], true)) {
        $status = 'down';
    }
    return [
        'id' => $id,
        'label' => $label,
        'status' => $status,
        'note' => $note,
        'http' => $http,
        'since' => $since,
    ];
}

function poa_health_apply_since(array $feeds, array $prev, int $now): array
{
    $out = [];
    foreach ($feeds as $feed) {
        if (!is_array($feed)) {
            continue;
        }
        $id = (string) ($feed['id'] ?? '');
        $status = (string) ($feed['status'] ?? 'down');
        $since = null;
        if ($status !== 'ok') {
            $was = is_array($prev[$id] ?? null) ? $prev[$id] : [];
            if (($was['status'] ?? '') === $status && isset($was['since']) && is_numeric($was['since'])) {
                $since = (int) $was['since'];
            } else {
                $since = $now;
            }
        }
        $feed['since'] = $since;
        $out[] = $feed;
    }
    return $out;
}

function poa_health_since_map(array $feeds): array
{
    $map = [];
    foreach ($feeds as $feed) {
        if (!is_array($feed)) {
            continue;
        }
        $id = (string) ($feed['id'] ?? '');
        if ($id === '') {
            continue;
        }
        $map[$id] = [
            'status' => (string) ($feed['status'] ?? ''),
            'since' => $feed['since'] ?? null,
        ];
    }
    return $map;
}

function poa_health_rollup(array $feeds): string
{
    $n = 0;
    $down = 0;
    $bad = 0;
    foreach ($feeds as $feed) {
        if (!is_array($feed)) {
            continue;
        }
        $n++;
        $st = (string) ($feed['status'] ?? '');
        if ($st === 'down') {
            $down++;
            $bad++;
        } elseif ($st === 'degraded') {
            $bad++;
        }
    }
    if ($n === 0) {
        return 'Down';
    }
    if ($down === $n) {
        return 'Down';
    }
    if ($bad > 0) {
        return 'Partial';
    }
    return 'OK';
}

function poa_swap_writes_from_book(?array $book, ?string $bookError): array
{
    if ($bookError !== null && $bookError !== '') {
        return [
            'status' => 'down',
            'market_state' => 'unknown',
            'note' => 'OTC book unread: ' . $bookError,
        ];
    }
    if (!is_array($book)) {
        return [
            'status' => 'down',
            'market_state' => 'unknown',
            'note' => 'OTC book missing',
        ];
    }
    $writes = $book['writes'] ?? null;
    $state = strtolower(trim((string) ($book['state'] ?? '')));
    $live = $book['swaps_live'] ?? null;
    $bookState = strtolower(trim((string) ($book['book_state'] ?? '')));
    if (is_string($writes) && $writes !== '') {
        $w = strtolower($writes);
        if (in_array($w, ['down', 'paused', 'halted', 'fail', 'failed'], true)) {
            return [
                'status' => 'down',
                'market_state' => 'paused',
                'note' => 'Book reports swap writes ' . $writes,
            ];
        }
    }
    if ($state !== '' && !in_array($state, ['available', 'ok', 'live', 'open'], true)) {
        return [
            'status' => 'down',
            'market_state' => 'paused',
            'note' => 'Book state ' . $state,
        ];
    }
    if ($live === false) {
        return [
            'status' => 'down',
            'market_state' => 'paused',
            'note' => 'Book swaps_live is false',
        ];
    }
    if ($bookState === 'stale') {
        return [
            'status' => 'degraded',
            'market_state' => 'open',
            'note' => 'Book marked stale',
        ];
    }
    return [
        'status' => 'ok',
        'market_state' => 'open',
        'note' => 'Book state available; write probe is public read-only (no order POST)',
    ];
}

function poa_health_origin_row(array $row, string $id, string $label, callable $okBody): array
{
    $err = $row['_error'] ?? null;
    $http = isset($row['_status']) ? (int) $row['_status'] : null;
    if ($err) {
        return poa_health_feed($id, $label, 'down', (string) $err, $http);
    }
    $body = $row['body'] ?? null;
    $check = $okBody($body);
    if ($check === true) {
        return poa_health_feed($id, $label, 'ok', null, $http);
    }
    if (is_array($check) && ($check['status'] ?? '') === 'degraded') {
        return poa_health_feed($id, $label, 'degraded', (string) ($check['note'] ?? 'degraded'), $http);
    }
    $note = is_string($check) ? $check : 'unexpected payload';
    return poa_health_feed($id, $label, 'down', $note, $http);
}

function poa_health_seals_from_close(?array $close, int $now): array
{
    if (!is_array($close)) {
        return poa_health_feed('seals', 'Seals', 'degraded', 'No last-close snapshot');
    }
    $pending = $close['basis']['pending'] ?? null;
    if (!is_array($pending)) {
        return poa_health_feed('seals', 'Seals', 'degraded', 'Last close has no pending map');
    }
    $newest = 0;
    $n = 0;
    foreach ($pending as $row) {
        if (!is_array($row) || !array_key_exists('grades', $row) || !is_array($row['grades'])) {
            continue;
        }
        foreach ($row['grades'] as $g) {
            if (!is_array($g)) {
                continue;
            }
            $n++;
            $ts = isset($g['ts']) && is_numeric($g['ts']) ? (int) $g['ts'] : 0;
            if ($ts > $newest) {
                $newest = $ts;
            }
        }
    }
    if ($n === 0) {
        return poa_health_feed('seals', 'Seals', 'degraded', 'No seals on last close');
    }
    if ($newest > 0 && ($now - $newest) > POA_SETTLE_DEGRADED_AFTER) {
        return poa_health_feed('seals', 'Seals', 'degraded', 'Newest seal ' . poa_format_ts($newest));
    }
    return poa_health_feed('seals', 'Seals', 'ok', $n . ' seals on last close');
}

function poa_health_settlement_from_close(?array $close, int $now): array
{
    if (!is_array($close)) {
        return poa_health_feed('settlement', 'Settlement freshness', 'degraded', 'No last-close snapshot');
    }
    $ts = isset($close['ts']) && is_numeric($close['ts']) ? (int) $close['ts'] : 0;
    $epoch = $close['epoch'] ?? null;
    if ($ts <= 0) {
        return poa_health_feed('settlement', 'Settlement freshness', 'degraded', 'Last close has no ts');
    }
    $age = $now - $ts;
    $note = 'Last close epoch ' . ($epoch === null ? '?' : (string) $epoch) . ' at ' . poa_format_ts($ts);
    if ($age > POA_SETTLE_DOWN_AFTER) {
        return poa_health_feed('settlement', 'Settlement freshness', 'down', $note);
    }
    if ($age > POA_SETTLE_DEGRADED_AFTER) {
        return poa_health_feed('settlement', 'Settlement freshness', 'degraded', $note);
    }
    return poa_health_feed('settlement', 'Settlement freshness', 'ok', $note);
}

function poa_health_epochs_basis(bool $present, ?int $ageSec): array
{
    if (!$present) {
        return poa_health_feed(
            'epochs_basis',
            'Address / epochs snapshot',
            'degraded',
            'Cron snapshot not on disk'
        );
    }
    if ($ageSec !== null && $ageSec > POA_EXPLORER_DERIVED_STALE_AFTER) {
        $mins = (int) floor($ageSec / 60);
        return poa_health_feed(
            'epochs_basis',
            'Address / epochs snapshot',
            'degraded',
            'Fast index ' . $mins . ' min behind'
        );
    }
    return poa_health_feed('epochs_basis', 'Address / epochs snapshot', 'ok', 'Cron snapshot present');
}

/**
 * @param array<string,mixed> $inputs
 * @param array<string,mixed> $prevSince
 * @return array<string,mixed>
 */
function poa_health_build(array $inputs, array $prevSince, int $now): array
{
    $feeds = [];
    $feeds[] = poa_health_origin_row(
        is_array($inputs['status'] ?? null) ? $inputs['status'] : poa_wrap(['_error' => 'missing status']),
        'status',
        'Status',
        static function ($body) {
            if (!is_array($body)) {
                return 'status body missing';
            }
            if (!isset($body['checkpoint']) && !isset($body['checkpoint_height']) && !isset($body['supply'])) {
                return 'no checkpoint or supply';
            }
            return true;
        }
    );
    $feeds[] = poa_health_origin_row(
        is_array($inputs['txs'] ?? null) ? $inputs['txs'] : poa_wrap(['_error' => 'missing txs']),
        'tx_lookup',
        'Tx lookup',
        static function ($body) {
            if (!is_array($body)) {
                return 'txs body missing';
            }
            if ($body === []) {
                return ['status' => 'degraded', 'note' => 'tx list empty'];
            }
            return true;
        }
    );
    $feeds[] = poa_health_origin_row(
        is_array($inputs['anchors'] ?? null) ? $inputs['anchors'] : poa_wrap(['_error' => 'missing anchors']),
        'anchors',
        'Anchors',
        static function ($body) {
            return is_array($body) ? true : 'anchors body missing';
        }
    );
    $feeds[] = poa_health_epochs_basis(
        (bool) ($inputs['derived_present'] ?? false),
        isset($inputs['derived_age']) && is_numeric($inputs['derived_age'])
            ? (int) $inputs['derived_age']
            : null
    );
    $close = is_array($inputs['last_close'] ?? null) ? $inputs['last_close'] : null;
    $feeds[] = poa_health_settlement_from_close($close, $now);
    $feeds[] = poa_health_seals_from_close($close, $now);
    $feeds[] = poa_health_origin_row(
        is_array($inputs['swaps'] ?? null) ? $inputs['swaps'] : poa_wrap(['_error' => 'missing swaps']),
        'swap_reads',
        'Swap reads',
        static function ($body) {
            return is_array($body) ? true : 'swaps body missing';
        }
    );
    $book = is_array($inputs['book'] ?? null) ? $inputs['book'] : null;
    $bookErr = isset($inputs['book_error']) ? (string) $inputs['book_error'] : null;
    if ($bookErr === '') {
        $bookErr = null;
    }
    $writes = poa_swap_writes_from_book($book, $bookErr);
    $feeds[] = poa_health_feed(
        'swap_writes',
        'Swap writes',
        (string) $writes['status'],
        (string) ($writes['note'] ?? null)
    );
    $feeds = poa_health_apply_since($feeds, $prevSince, $now);
    $overall = poa_health_rollup($feeds);
    return [
        'overall' => $overall,
        'probed_at' => $now,
        'ttl_s' => POA_HEALTH_TTL,
        'read_only' => true,
        'swap_writes' => $writes + ['since' => null],
        'feeds' => $feeds,
    ];
}

function poa_health_load_since(): array
{
    $row = poa_read_derived_json('health-since');
    return is_array($row) ? $row : [];
}

function poa_health_run(?int $now = null): array
{
    $now = $now ?? time();
    return poa_with_file_lock(poa_cache_dir() . '/lock-health', static function () use ($now): array {
        $status = poa_status();
        $txs = poa_recent_txs_row();
        $anchors = poa_anchors_row();
        $swaps = poa_json_get('/api/swaps');
        $bookRow = poa_url_json(poa_swap_book_url(), POA_MARKET_TTL);
        $mtime = poa_derived_mtime();
        $age = $mtime === null ? null : ($now - $mtime);
        $body = poa_health_build([
            'status' => $status,
            'txs' => $txs,
            'anchors' => $anchors,
            'swaps' => $swaps,
            'book' => is_array($bookRow['body']) ? $bookRow['body'] : null,
            'book_error' => $bookRow['_error'] ?? null,
            'derived_present' => poa_derived_present(),
            'derived_age' => $age,
            'last_close' => poa_derived_last_close(),
        ], poa_health_load_since(), $now);
        foreach ($body['feeds'] as $feed) {
            if (($feed['id'] ?? '') === 'swap_writes') {
                $body['swap_writes']['since'] = $feed['since'] ?? null;
                $body['swap_writes']['status'] = $feed['status'] ?? $body['swap_writes']['status'];
            }
        }
        @file_put_contents(poa_health_file('health'), json_encode($body, JSON_UNESCAPED_SLASHES));
        @file_put_contents(poa_health_file('health-since'), json_encode(poa_health_since_map($body['feeds']), JSON_UNESCAPED_SLASHES));
        return poa_wrap(['_cache' => 'fresh', '_status' => 200, 'body' => $body]);
    });
}

function poa_health_row(): array
{
    $cached = poa_read_derived_json('health');
    if (is_array($cached) && isset($cached['probed_at']) && is_numeric($cached['probed_at'])) {
        if ((time() - (int) $cached['probed_at']) < POA_HEALTH_TTL) {
            return poa_wrap(['_cache' => 'hit', '_status' => 200, 'body' => $cached]);
        }
    }
    return poa_health_run();
}

function poa_health_banner_html(?array $body = null): string
{
    if ($body === null) {
        $row = poa_health_row();
        $body = is_array($row['body'] ?? null) ? $row['body'] : null;
    }
    if (!is_array($body)) {
        return '';
    }
    $overall = (string) ($body['overall'] ?? 'Down');
    $cls = 'health-ok';
    if ($overall === 'Partial') {
        $cls = 'health-partial';
    } elseif ($overall === 'Down') {
        $cls = 'health-down';
    }
    $bits = [];
    foreach ((array) ($body['feeds'] ?? []) as $feed) {
        if (!is_array($feed)) {
            continue;
        }
        $st = (string) ($feed['status'] ?? '');
        if ($st === 'ok') {
            continue;
        }
        $label = (string) ($feed['label'] ?? $feed['id'] ?? 'feed');
        $since = $feed['since'] ?? null;
        $bit = $label . ' ' . $st;
        if (is_numeric($since) && (int) $since > 0) {
            $bit .= ' since ' . poa_format_ts((int) $since);
        }
        $bits[] = $bit;
    }
    $detail = $bits === [] ? 'All probed feeds ok.' : implode(' · ', $bits);
    return '<div class="health-banner ' . $cls . '" role="status">'
        . '<strong>Network ' . poa_h($overall) . '.</strong> '
        . poa_h($detail)
        . ' <a href="health.php">Health detail</a>'
        . '</div>';
}

<?php
declare(strict_types=1);

/**
 * OTC book (poaswap.io). Posted intents, filled whole. Not an AMM.
 * Labels are last trade / best offer only.
 */

function poa_usd_rates_from_coingecko(?array $body): array
{
    $rates = ['USDC' => 1.0];
    if (!is_array($body)) {
        return $rates;
    }
    if (isset($body['solana']['usd']) && is_numeric($body['solana']['usd'])) {
        $rates['SOL'] = (float) $body['solana']['usd'];
    }
    if (isset($body['ethereum']['usd']) && is_numeric($body['ethereum']['usd'])) {
        $rates['ETH'] = (float) $body['ethereum']['usd'];
    }
    return $rates;
}

function poa_usd_rates_from_book(?array $book): array
{
    $rates = ['USDC' => 1.0];
    $usd = is_array($book) && is_array($book['usd'] ?? null) ? $book['usd'] : [];
    $src = is_array($usd['rates'] ?? null) ? $usd['rates'] : [];
    foreach (['SOL', 'ETH', 'USDC'] as $asset) {
        if (isset($src[$asset]) && is_numeric($src[$asset])) {
            $rates[$asset] = (float) $src[$asset];
        }
    }
    return $rates;
}

function poa_usd_implied(?float $per1m, string $asset, array $rates): ?float
{
    if ($per1m === null) {
        return null;
    }
    $asset = strtoupper($asset);
    if (!isset($rates[$asset]) || !is_numeric($rates[$asset])) {
        return null;
    }
    return ($per1m * (float) $rates[$asset]) / 1000000.0;
}

function poa_num_or_null($v): ?float
{
    if ($v === null || $v === '') {
        return null;
    }
    if (!is_numeric($v)) {
        return null;
    }
    return (float) $v;
}

function poa_market_pair_from_book_pair(array $p, array $rates): array
{
    $asset = strtoupper((string) ($p['asset'] ?? ''));
    $chain = (string) ($p['chain'] ?? '');
    $key = (string) ($p['key'] ?? ($asset . '|' . $chain));
    $best = poa_num_or_null($p['best_ask'] ?? null);
    $last = poa_num_or_null($p['last'] ?? null);
    $asks = is_array($p['asks'] ?? null) ? $p['asks'] : [];
    $size = null;
    $want = null;
    if ($asks !== []) {
        $top = is_array($asks[0]) ? $asks[0] : [];
        $size = poa_num_or_null($top['poa'] ?? null);
        $want = poa_num_or_null($top['want'] ?? null);
        if ($best === null) {
            $best = poa_num_or_null($top['px'] ?? null);
        }
    }
    $lastTs = isset($p['last_ts']) && is_numeric($p['last_ts']) ? (int) $p['last_ts'] : null;
    return [
        'key' => $key,
        'asset' => $asset,
        'chain' => $chain,
        'chain_name' => (string) ($p['chain_name'] ?? $chain),
        'open_offers' => count($asks),
        'best_offer' => $best === null ? null : [
            'label' => 'best offer',
            'per_1m_poa' => $best,
            'poa_size' => $size,
            'want' => $want,
            'usd_per_poa' => poa_usd_implied($best, $asset, $rates),
        ],
        'last_trade' => $last === null ? null : [
            'label' => 'last trade',
            'per_1m_poa' => $last,
            'ts' => $lastTs,
            'usd_per_poa' => poa_usd_implied($last, $asset, $rates),
        ],
        'last_label' => isset($p['last_label']) ? (string) $p['last_label'] : null,
        'ask_only' => (bool) ($p['ask_only'] ?? false),
    ];
}

function poa_market_history_append(array $history, array $snap, int $now, int $minGap, int $cap): array
{
    $lastTs = 0;
    if ($history !== []) {
        $tail = $history[count($history) - 1];
        if (is_array($tail) && isset($tail['ts']) && is_numeric($tail['ts'])) {
            $lastTs = (int) $tail['ts'];
        }
    }
    if ($lastTs > 0 && ($now - $lastTs) < $minGap) {
        return $history;
    }
    $history[] = $snap;
    $n = count($history);
    if ($n > $cap) {
        $history = array_values(array_slice($history, $n - $cap));
    }
    return $history;
}

function poa_market_history_series(array $history, string $pairKey, string $field): array
{
    $out = [];
    foreach ($history as $row) {
        if (!is_array($row)) {
            continue;
        }
        $ts = isset($row['ts']) && is_numeric($row['ts']) ? (int) $row['ts'] : 0;
        $pairs = is_array($row['pairs'] ?? null) ? $row['pairs'] : [];
        $cell = $pairs[$pairKey] ?? null;
        $v = is_array($cell) ? poa_num_or_null($cell[$field] ?? null) : null;
        $out[] = ['ts' => $ts, 'value' => $v];
    }
    return $out;
}

function poa_market_snapshot_from_pairs(array $pairs, int $now): array
{
    $map = [];
    foreach ($pairs as $p) {
        if (!is_array($p)) {
            continue;
        }
        $key = (string) ($p['key'] ?? '');
        if ($key === '') {
            continue;
        }
        $map[$key] = [
            'best_offer' => $p['best_offer']['per_1m_poa'] ?? null,
            'last_trade' => $p['last_trade']['per_1m_poa'] ?? null,
        ];
    }
    return ['ts' => $now, 'pairs' => $map];
}

/**
 * @param array<string,mixed> $book
 * @param array<string,float> $rates
 * @param array<int,array<string,mixed>> $history
 * @param array<string,mixed> $swapWrites
 * @return array<string,mixed>
 */
function poa_market_from_book(array $book, array $rates, array $history, array $swapWrites, int $now): array
{
    $pairs = [];
    foreach ((array) ($book['pairs'] ?? []) as $p) {
        if (is_array($p)) {
            $pairs[] = poa_market_pair_from_book_pair($p, $rates);
        }
    }
    $primary = 'SOL|solana';
    $state = (string) ($swapWrites['market_state'] ?? 'unknown');
    $usdSrc = is_array($book['usd'] ?? null) ? (string) ($book['usd']['src'] ?? 'CoinGecko') : 'CoinGecko';
    return [
        'otc' => true,
        'mechanism' => 'Posted intents/offers, filled whole. No liquidity pool. Not an AMM.',
        'venue' => 'poaswap.io via Emblem',
        'fee_bp' => 100,
        'disclaimer' => 'Thin OTC book. Figures are last trade and best offer, never a live market price.',
        'market_state' => $state,
        'swap_writes' => $swapWrites,
        'usd_ref' => [
            'source' => $usdSrc,
            'rates' => $rates,
        ],
        'primary' => $primary,
        'pairs' => $pairs,
        'history' => [
            'best_offer' => poa_market_history_series($history, $primary, 'best_offer'),
            'last_trade' => poa_market_history_series($history, $primary, 'last_trade'),
        ],
        'as_of' => $now,
        'source' => poa_swap_book_url(),
    ];
}

function poa_market_load_history(): array
{
    $row = poa_read_derived_json('market-history');
    return is_array($row) ? $row : [];
}

function poa_market_refresh(?int $now = null): array
{
    $now = $now ?? time();
    return poa_with_file_lock(poa_cache_dir() . '/lock-market', static function () use ($now): array {
        $bookRow = poa_url_json(poa_swap_book_url(), POA_MARKET_TTL);
        $book = is_array($bookRow['body']) ? $bookRow['body'] : null;
        $bookErr = $bookRow['_error'] ?? null;
        $cg = poa_url_json(poa_coingecko_url(), POA_USD_TTL);
        $rates = poa_usd_rates_from_coingecko(is_array($cg['body']) ? $cg['body'] : null);
        $bookRates = poa_usd_rates_from_book($book);
        if (!isset($rates['SOL']) && isset($bookRates['SOL'])) {
            $rates['SOL'] = $bookRates['SOL'];
        }
        if (!isset($rates['ETH']) && isset($bookRates['ETH'])) {
            $rates['ETH'] = $bookRates['ETH'];
        }
        $writes = poa_swap_writes_from_book($book, is_string($bookErr) ? $bookErr : null);
        $health = poa_read_derived_json('health');
        if (is_array($health) && is_array($health['swap_writes'] ?? null)) {
            $writes['since'] = $health['swap_writes']['since'] ?? ($writes['since'] ?? null);
            if (($health['swap_writes']['status'] ?? '') !== '') {
                $writes['status'] = $health['swap_writes']['status'];
            }
            if (($health['swap_writes']['market_state'] ?? '') !== '') {
                $writes['market_state'] = $health['swap_writes']['market_state'];
            }
        }
        if (!is_array($book)) {
            $body = [
                'otc' => true,
                'error' => $bookErr ?: 'book unread',
                'market_state' => $writes['market_state'] ?? 'unknown',
                'swap_writes' => $writes,
                'pairs' => [],
                'disclaimer' => 'Thin OTC book. Figures are last trade and best offer, never a live market price.',
            ];
            return poa_wrap(['_error' => (string) ($bookErr ?: 'book unread'), '_cache' => $bookRow['_cache'] ?? 'miss', 'body' => $body]);
        }
        $history = poa_market_load_history();
        $body = poa_market_from_book($book, $rates, $history, $writes, $now);
        $histSnap = poa_market_snapshot_from_pairs($body['pairs'], $now);
        $history = poa_market_history_append($history, $histSnap, $now, POA_MARKET_HISTORY_MIN_GAP, POA_MARKET_HISTORY_CAP);
        $body['history'] = [
            'best_offer' => poa_market_history_series($history, (string) $body['primary'], 'best_offer'),
            'last_trade' => poa_market_history_series($history, (string) $body['primary'], 'last_trade'),
        ];
        @file_put_contents(poa_health_file('market'), json_encode($body, JSON_UNESCAPED_SLASHES));
        @file_put_contents(poa_health_file('market-history'), json_encode($history, JSON_UNESCAPED_SLASHES));
        return poa_wrap(['_cache' => $bookRow['_cache'] ?? 'fresh', '_status' => 200, 'body' => $body]);
    });
}

function poa_market_row(): array
{
    $cached = poa_read_derived_json('market');
    if (is_array($cached) && isset($cached['as_of']) && is_numeric($cached['as_of'])) {
        if ((time() - (int) $cached['as_of']) < POA_MARKET_TTL) {
            return poa_wrap(['_cache' => 'hit', '_status' => 200, 'body' => $cached]);
        }
    }
    return poa_market_refresh();
}

function poa_market_primary(?array $body): ?array
{
    if (!is_array($body)) {
        return null;
    }
    $want = (string) ($body['primary'] ?? 'SOL|solana');
    foreach ((array) ($body['pairs'] ?? []) as $p) {
        if (is_array($p) && (string) ($p['key'] ?? '') === $want) {
            return $p;
        }
    }
    return null;
}

function poa_fmt_per_1m(?float $v, string $asset): string
{
    if ($v === null) {
        return '—';
    }
    $s = rtrim(rtrim(sprintf('%.6f', $v), '0'), '.');
    return $s . ' ' . $asset . ' / 1M POA';
}

function poa_fmt_usd_per_poa(?float $v): string
{
    if ($v === null) {
        return '—';
    }
    return '$' . sprintf('%.6f', $v) . ' / POA (implied)';
}

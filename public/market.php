<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/reports_ui.php';

$row = poa_market_row();
$body = is_array($row['body'] ?? null) ? $row['body'] : [];
$err = $row['_error'] ?? null;
$primary = poa_market_primary($body);

poa_layout_start('OTC book', 'market');
echo '<h1>OTC book</h1>';
echo '<p class="lede">Posted intents on poaswap.io, filled whole, 1% fee via Emblem. Not an AMM and not a live market price. Thin book — last trade and best offer only.</p>';

if ($err) {
    poa_error_box((string) $err);
}

$state = (string) ($body['market_state'] ?? 'unknown');
$writes = is_array($body['swap_writes'] ?? null) ? $body['swap_writes'] : [];
poa_stats([
    'Book' => poa_h($state),
    'Swap writes' => poa_h((string) ($writes['status'] ?? '—')),
    'Fee' => '1%',
    'Primary pair' => 'POA / SOL (Solana)',
]);

if ($primary) {
    $best = is_array($primary['best_offer'] ?? null) ? $primary['best_offer'] : null;
    $last = is_array($primary['last_trade'] ?? null) ? $primary['last_trade'] : null;
    poa_panel_start('POA / SOL — last trade and best offer', (string) ($body['disclaimer'] ?? ''));
    poa_kv([
        'Last trade' => poa_h(poa_fmt_per_1m(isset($last['per_1m_poa']) ? (float) $last['per_1m_poa'] : null, (string) $primary['asset'])),
        'Last trade implied USD' => poa_h(poa_fmt_usd_per_poa(isset($last['usd_per_poa']) ? (float) $last['usd_per_poa'] : null)),
        'Last fill time' => isset($last['ts']) ? poa_h(poa_format_ts($last['ts'])) : '—',
        'Best offer' => poa_h(poa_fmt_per_1m(isset($best['per_1m_poa']) ? (float) $best['per_1m_poa'] : null, (string) $primary['asset'])),
        'Best offer implied USD' => poa_h(poa_fmt_usd_per_poa(isset($best['usd_per_poa']) ? (float) $best['usd_per_poa'] : null)),
        'Open offers' => poa_h((string) ($primary['open_offers'] ?? 0)),
        'Note' => poa_h((string) ($primary['last_label'] ?? '—')),
    ]);
    $hist = is_array($body['history'] ?? null) ? $body['history'] : [];
    $offerSeries = is_array($hist['best_offer'] ?? null) ? $hist['best_offer'] : [];
    $tradeSeries = is_array($hist['last_trade'] ?? null) ? $hist['last_trade'] : [];
    if (count($offerSeries) >= 2) {
        $labels = [];
        $offerVals = [];
        $tradeVals = [];
        foreach ($offerSeries as $i => $pt) {
            $labels[] = isset($pt['ts']) ? gmdate('m-d H:i', (int) $pt['ts']) : (string) $i;
            $offerVals[] = isset($pt['value']) && is_numeric($pt['value']) ? (float) $pt['value'] : null;
            $tr = $tradeSeries[$i] ?? null;
            $tradeVals[] = is_array($tr) && isset($tr['value']) && is_numeric($tr['value']) ? (float) $tr['value'] : null;
        }
        echo poa_svg_dual_line($labels, $offerVals, $tradeVals, 'best offer', 'last trade');
        echo '<p class="hint">Separate series. Offers move more often than fills.</p>';
    }
    poa_panel_end();
}

poa_panel_start('All pairs');
echo '<div class="table-wrap"><table class="txs"><thead><tr>';
echo '<th>Pair</th><th>Last trade</th><th>Best offer</th><th>Open offers</th>';
echo '</tr></thead><tbody>';
foreach ((array) ($body['pairs'] ?? []) as $p) {
    if (!is_array($p)) {
        continue;
    }
    $asset = (string) ($p['asset'] ?? '');
    $last = is_array($p['last_trade'] ?? null) ? $p['last_trade'] : null;
    $best = is_array($p['best_offer'] ?? null) ? $p['best_offer'] : null;
    echo '<tr>';
    echo '<td>POA / ' . poa_h($asset) . ' (' . poa_h((string) ($p['chain_name'] ?? '')) . ')</td>';
    echo '<td>' . poa_h(poa_fmt_per_1m(isset($last['per_1m_poa']) ? (float) $last['per_1m_poa'] : null, $asset)) . '</td>';
    echo '<td>' . poa_h(poa_fmt_per_1m(isset($best['per_1m_poa']) ? (float) $best['per_1m_poa'] : null, $asset)) . '</td>';
    echo '<td>' . poa_h((string) ($p['open_offers'] ?? 0)) . '</td>';
    echo '</tr>';
}
echo '</tbody></table></div>';
poa_panel_end();

$usd = is_array($body['usd_ref'] ?? null) ? $body['usd_ref'] : [];
$rates = is_array($usd['rates'] ?? null) ? $usd['rates'] : [];
poa_panel_start('USD reference');
echo '<p class="hint">Cached ' . poa_h((string) ($usd['source'] ?? 'CoinGecko')) . ' rate. Implied USD is last trade or best offer times that rate, not a spot POA market.</p>';
poa_kv([
    'SOL/USD' => isset($rates['SOL']) ? poa_h((string) $rates['SOL']) : '—',
    'ETH/USD' => isset($rates['ETH']) ? poa_h((string) $rates['ETH']) : '—',
]);
echo '<p><a href="health.php">Network health (includes swap-write state) →</a></p>';
poa_panel_end();

poa_layout_end($row + ['as_of' => isset($body['as_of']) ? poa_format_ts($body['as_of']) : null]);

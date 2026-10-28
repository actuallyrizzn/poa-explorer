<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

$row = poa_health_row();
$body = is_array($row['body'] ?? null) ? $row['body'] : [];
$err = $row['_error'] ?? null;

poa_layout_start('Health', 'health');
echo '<h1>Network health</h1>';
echo '<p class="lede">Read-only probes of the public feeds this explorer uses. Cached ~'
    . (int) POA_HEALTH_TTL
    . 's. This is not poascan’s agent directory, governance, epoch countdown, or a swap desk.</p>';

if ($err) {
    poa_error_box((string) $err);
}

$overall = (string) ($body['overall'] ?? 'Down');
poa_stats([
    'Overall' => poa_h($overall),
    'Probed' => isset($body['probed_at']) ? poa_h(poa_format_ts($body['probed_at'])) : '—',
    'Cache' => poa_h((string) ($row['_cache'] ?? '—')),
]);

poa_panel_start('Feeds');
echo '<div class="table-wrap"><table class="txs"><thead><tr>';
echo '<th>Feed</th><th>Status</th><th>Since</th><th>HTTP</th><th>Note</th>';
echo '</tr></thead><tbody>';
foreach ((array) ($body['feeds'] ?? []) as $feed) {
    if (!is_array($feed)) {
        continue;
    }
    echo '<tr>';
    echo '<td>' . poa_h((string) ($feed['label'] ?? '')) . '</td>';
    echo '<td>' . poa_h((string) ($feed['status'] ?? '')) . '</td>';
    echo '<td>' . poa_h(isset($feed['since']) && is_numeric($feed['since']) ? poa_format_ts((int) $feed['since']) : '—') . '</td>';
    echo '<td>' . poa_h($feed['http'] === null ? '—' : (string) $feed['http']) . '</td>';
    echo '<td>' . poa_h((string) ($feed['note'] ?? '—')) . '</td>';
    echo '</tr>';
}
echo '</tbody></table></div>';
poa_panel_end();

poa_panel_start('Swap writes', 'Public book fields only. This explorer never POSTs an order.');
$w = is_array($body['swap_writes'] ?? null) ? $body['swap_writes'] : [];
poa_kv([
    'Market state' => poa_h((string) ($w['market_state'] ?? 'unknown')),
    'Status' => poa_h((string) ($w['status'] ?? '—')),
    'Note' => poa_h((string) ($w['note'] ?? '—')),
]);
echo '<p><a href="market.php">OTC book →</a></p>';
poa_panel_end();

poa_layout_end($row);

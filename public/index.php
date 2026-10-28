<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

$status = poa_status();
$body = is_array($status['body']) ? $status['body'] : [];
$txs = poa_recent_txs();
$err = $status['_error'] ?? null;

poa_layout_start('Tip', 'home');
echo '<h1>Tip</h1>';

if ($err) {
    poa_error_box((string) $err);
}

$checkpoint = $body['checkpoint']['height'] ?? $body['checkpoint_height'] ?? null;
$chainId = $body['chain_id'] ?? $body['network'] ?? '—';

poa_stats([
    'Network' => poa_h((string) $chainId),
    'Checkpoint height' => poa_h($checkpoint === null ? '—' : (string) $checkpoint),
    'Transactions' => poa_h(isset($body['txs']) ? (string) $body['txs'] : '—'),
    'Final' => poa_h(isset($body['final']) ? (string) $body['final'] : '—'),
    'Epochs closed' => poa_h(isset($body['epochs_closed']) ? (string) $body['epochs_closed'] : '—'),
    'Holders' => poa_h(isset($body['holders']) ? (string) $body['holders'] : '—'),
]);

poa_panel_start('Chain tip');
poa_kv([
    'State root' => isset($body['state_root']) ? '<span class="mono">' . poa_h((string) $body['state_root']) . '</span>' : '—',
    'Supply' => poa_h(isset($body['supply']) ? (string) $body['supply'] : '—'),
    'Agents joined' => poa_h(isset($body['agents_joined']) ? (string) $body['agents_joined'] : '—'),
    'Checkpoint cutoff' => isset($body['checkpoint']['cutoff_ts'])
        ? poa_h(poa_format_ts($body['checkpoint']['cutoff_ts']))
        : '—',
]);
echo '<p><a href="checkpoint.php">Browse checkpoints →</a></p>';
poa_panel_end();

$market = poa_market_row();
$mbody = is_array($market['body'] ?? null) ? $market['body'] : [];
$primary = poa_market_primary($mbody);
$best = is_array($primary) && is_array($primary['best_offer'] ?? null) ? $primary['best_offer'] : null;
$last = is_array($primary) && is_array($primary['last_trade'] ?? null) ? $primary['last_trade'] : null;
$w = is_array($mbody['swap_writes'] ?? null) ? $mbody['swap_writes'] : [];
poa_panel_start('OTC book — POA / SOL', 'Not a live market price. Last trade and best offer on a thin posted-intent book.');
poa_kv([
    'Last trade' => poa_h(poa_fmt_per_1m(isset($last['per_1m_poa']) ? (float) $last['per_1m_poa'] : null, 'SOL')),
    'Best offer' => poa_h(poa_fmt_per_1m(isset($best['per_1m_poa']) ? (float) $best['per_1m_poa'] : null, 'SOL')),
        'Implied USD' => poa_h(poa_fmt_usd_per_poa(isset($last['usd_per_poa']) ? (float) $last['usd_per_poa'] : null)),
    'Book' => poa_h((string) ($mbody['market_state'] ?? 'unknown')),
    'Swap writes' => poa_h((string) ($w['status'] ?? '—')),
]);
echo '<p><a href="market.php">Full OTC book →</a></p>';
poa_panel_end();

poa_panel_start('Recent transactions', 'Height on each row is that transaction’s ledger height. Chain height is the checkpoint above. Open-epoch txs can sit above the checkpoint.');
poa_tx_table($txs);
poa_panel_end();

poa_layout_end($status + ['as_of' => $body['as_of'] ?? null, 'source' => $body['source'] ?? null]);

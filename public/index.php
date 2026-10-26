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

poa_panel_start('Recent transactions', 'Height on each row is that transaction’s ledger height. Chain height is the checkpoint above. Open-epoch txs can sit above the checkpoint.');
poa_tx_table($txs);
poa_panel_end();

poa_layout_end($status + ['as_of' => $body['as_of'] ?? null, 'source' => $body['source'] ?? null]);

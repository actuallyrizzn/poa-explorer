<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

$want = trim((string) ($_GET['height'] ?? ''));
$status = poa_status();
$body = is_array($status['body']) ? $status['body'] : [];
$anchors = poa_anchors();
$err = $status['_error'] ?? null;

poa_layout_start('Checkpoints', 'checkpoint');
echo '<h1>Checkpoints</h1>';

if ($err) {
    poa_error_box((string) $err);
}

$chainHeight = $body['checkpoint']['height'] ?? $body['checkpoint_height'] ?? null;
poa_stats([
    'Checkpoint height' => poa_h($chainHeight === null ? '—' : (string) $chainHeight),
    'Anchors shown' => poa_h((string) count($anchors)),
]);

poa_panel_start('Chain tip', 'PoA’s chain height is checkpoint height, not an EVM block. Each transaction may also carry its own height on the tx row.');
poa_kv([
    'State root' => isset($body['state_root']) ? '<span class="mono">' . poa_h((string) $body['state_root']) . '</span>' : '—',
    'Checkpoint cutoff' => isset($body['checkpoint']['cutoff_ts'])
        ? poa_h(poa_format_ts($body['checkpoint']['cutoff_ts']))
        : '—',
]);
poa_panel_end();

if ($want !== '' && poa_is_height($want)) {
    poa_panel_start('Height ' . $want);
    $hit = null;
    foreach ($anchors as $a) {
        if ((string) ($a['height'] ?? '') === $want) {
            $hit = $a;
            break;
        }
    }
    if ($hit) {
        poa_kv([
            'Height' => poa_h((string) $hit['height']),
            'State root' => '<span class="mono">' . poa_h((string) ($hit['state_root'] ?? '')) . '</span>',
            'Anchor chain' => poa_h((string) ($hit['chain'] ?? '—')),
            'Anchor tx' => '<span class="mono">' . poa_h((string) ($hit['tx_hash'] ?? '')) . '</span>',
            'Anchored at' => poa_h(isset($hit['anchored_at']) ? (string) $hit['anchored_at'] : '—'),
        ]);
    } elseif ((string) $chainHeight === $want) {
        echo '<p>This is the current checkpoint height from /api/status.</p>';
    } else {
        echo '<p class="hint">No Base/Solana anchor row in the latest ' . (string) POA_EXPLORER_ANCHOR_LIMIT . ' anchors matched this height. It may still be a valid ledger height for a transaction.</p>';
    }
    poa_panel_end();
}

poa_panel_start('Recent external anchors', 'State roots posted to Base or Solana. Newest first. PoA has no EVM blocks; these are the public height receipts.');
echo '<div class="table-wrap"><table class="txs"><thead><tr>';
echo '<th>Height</th><th>Chain</th><th>State root</th><th>Anchor tx</th><th>When</th>';
echo '</tr></thead><tbody>';
if (!$anchors) {
    echo '<tr><td colspan="5">No anchors loaded.</td></tr>';
}
foreach ($anchors as $a) {
    $h = (string) ($a['height'] ?? '');
    echo '<tr>';
    echo '<td><a href="checkpoint.php?height=' . rawurlencode($h) . '">' . poa_h($h) . '</a></td>';
    echo '<td>' . poa_h((string) ($a['chain'] ?? '—')) . '</td>';
    echo '<td class="mono">' . poa_h(poa_short_id((string) ($a['state_root'] ?? ''), 10)) . '</td>';
    echo '<td class="mono">' . poa_h(poa_short_id((string) ($a['tx_hash'] ?? ''), 10)) . '</td>';
    echo '<td>' . poa_h(isset($a['anchored_at']) ? (string) $a['anchored_at'] : '—') . '</td>';
    echo '</tr>';
}
echo '</tbody></table></div>';
poa_panel_end();

poa_layout_end($status + [
    'as_of' => $body['as_of'] ?? null,
    'source' => $body['source'] ?? null,
]);

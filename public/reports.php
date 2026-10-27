<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

$row = poa_reports_row();
$body = is_array($row['body']) ? $row['body'] : [];
$err = $row['_error'] ?? null;
$net = is_array($body['network'] ?? null) ? $body['network'] : [];
$settlement = is_array($body['settlement'] ?? null) ? $body['settlement'] : [];
$closes = is_array($settlement['rows'] ?? null) ? $settlement['rows'] : [];
$backlog = is_array($body['grading_backlog'] ?? null) ? $body['grading_backlog'] : [];
$agreement = is_array($body['close_agreement'] ?? null) ? $body['close_agreement'] : [];
$payouts = is_array($body['payouts'] ?? null) ? $body['payouts'] : [];

poa_layout_start('Reports', 'reports');
echo '<h1>Reports</h1>';
echo '<p class="hint">Network-level views over the same public JSON as the rest of this explorer. Every figure cites its source. Missing data stays labeled. No wallet, no hosted API, no locked vs spendable split, no forecasts. Settlement lives here, not on its own page.</p>';

if ($err) {
    poa_error_box((string) $err);
}

$base = $net['last_base_anchor'] ?? null;
$sol = $net['last_solana_anchor'] ?? null;
poa_panel_start('Network', (string) (($net['source']['status'] ?? '') . '. Tx types are the recent feed, not all-time.'));
poa_stats([
    'Checkpoint height' => poa_h(($net['checkpoint_height'] ?? null) === null ? '—' : (string) $net['checkpoint_height']),
    'Epochs closed' => poa_h((string) ($net['epochs_closed'] ?? 0)),
    'Cadence (mean)' => poa_h(($net['epoch_cadence_hours_mean'] ?? null) === null ? '—' : ((string) $net['epoch_cadence_hours_mean'] . ' h')),
    'Status tx count' => poa_h(($net['status_tx_count'] ?? null) === null ? '—' : (string) $net['status_tx_count']),
    'Attestations (last close)' => poa_h(($net['attestations_last_close'] ?? null) === null ? '—' : (string) $net['attestations_last_close']),
    'Last Base anchor' => poa_h(is_array($base) ? ((string) ($base['height'] ?? '—') . ' · ' . (string) ($base['anchored_at'] ?? '')) : '—'),
    'Last Solana anchor' => poa_h(is_array($sol) ? ((string) ($sol['height'] ?? '—') . ' · ' . (string) ($sol['anchored_at'] ?? '')) : '—'),
]);
$types = is_array($net['recent_tx_types'] ?? null) ? $net['recent_tx_types'] : [];
if ($types) {
    echo '<p class="hint">Recent tx types: ';
    $bits = [];
    foreach ($types as $t => $c) {
        $bits[] = poa_h((string) $t) . ' ' . poa_h((string) $c);
    }
    echo implode(' · ', $bits) . '</p>';
}
echo '<p class="hint">' . poa_h((string) ($net['recent_tx_note'] ?? '')) . '</p>';
poa_panel_end();

poa_panel_start(
    'Settlement by birth cohort',
    (string) (($settlement['source'] ?? '') . '. ' . ($settlement['test_case'] ?? ''))
);
echo '<p class="hint">Sealed is pending rows with at least one grade in that close snapshot. Settled is basis.settled. Paid is addresses with rewards amount &gt; 0. Birth for a settled row is prior-close pending.since (prior epoch minus since). Still-pending birth is this close epoch minus since.</p>';
echo '<div class="table-wrap"><table class="txs"><thead><tr>';
echo '<th>Close</th><th>Time</th><th>Sealed</th><th>Settled</th><th>Passed</th><th>Failed</th><th>Paid</th><th>Still pending</th><th>Established</th>';
echo '</tr></thead><tbody>';
if (!$closes) {
    echo '<tr><td colspan="9">No close records.</td></tr>';
}
foreach ($closes as $c) {
    if (!is_array($c)) {
        continue;
    }
    echo '<tr>';
    echo '<td class="mono"><a href="api/close.php?epoch=' . poa_h((string) (int) ($c['epoch'] ?? 0)) . '">' . poa_h((string) ($c['epoch'] ?? '')) . '</a></td>';
    echo '<td>' . poa_h(poa_format_ts($c['ts'] ?? null)) . '</td>';
    echo '<td>' . poa_h((string) (int) ($c['sealed'] ?? 0)) . '</td>';
    echo '<td>' . poa_h((string) (int) ($c['settled'] ?? 0)) . '</td>';
    echo '<td>' . poa_h((string) (int) ($c['passed'] ?? 0)) . '</td>';
    echo '<td>' . poa_h((string) (int) ($c['failed'] ?? 0)) . '</td>';
    echo '<td>' . poa_h((string) (int) ($c['paid'] ?? 0)) . '</td>';
    echo '<td>' . poa_h((string) (int) ($c['still_pending'] ?? 0)) . '</td>';
    echo '<td>' . poa_h((string) (int) ($c['established_after'] ?? 0)) . '</td>';
    echo '</tr>';
}
echo '</tbody></table></div>';

foreach ($closes as $c) {
    if (!is_array($c)) {
        continue;
    }
    $ep = (int) ($c['epoch'] ?? -1);
    echo '<h3>Cohorts at close ' . poa_h((string) $ep) . '</h3>';
    echo '<p class="hint">' . poa_h((string) ($c['source'] ?? '')) . ' · ' . poa_h((string) ($c['birth_rule'] ?? '')) . '</p>';
    $cohorts = is_array($c['cohorts'] ?? null) ? $c['cohorts'] : [];
    echo '<div class="table-wrap"><table class="txs"><thead><tr>';
    echo '<th>Birth epoch</th><th>Passed</th><th>Failed</th><th>Settled</th><th>Still pending</th>';
    echo '</tr></thead><tbody>';
    if (!$cohorts) {
        echo '<tr><td colspan="5">No cohort rows.</td></tr>';
    }
    foreach ($cohorts as $co) {
        if (!is_array($co)) {
            continue;
        }
        $label = $co['birth_epoch'] === null ? 'unknown' : (string) $co['birth_epoch'];
        echo '<tr>';
        echo '<td>' . poa_h($label) . '</td>';
        echo '<td>' . poa_h((string) (int) ($co['passed'] ?? 0)) . '</td>';
        echo '<td>' . poa_h((string) (int) ($co['failed'] ?? 0)) . '</td>';
        echo '<td>' . poa_h((string) (int) ($co['settled'] ?? 0)) . '</td>';
        echo '<td>' . poa_h((string) (int) ($co['still_pending'] ?? 0)) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}
poa_panel_end();

poa_panel_start(
    'Grading backlog (last close)',
    (string) ($backlog['source'] ?? 'last-close basis.pending')
);
poa_stats([
    'As of close' => poa_h(($backlog['as_of_epoch'] ?? null) === null ? '—' : (string) $backlog['as_of_epoch']),
    'Pending' => poa_h((string) (int) ($backlog['pending'] ?? 0)),
    'With panel' => poa_h((string) (int) ($backlog['with_panel'] ?? 0)),
    'Without panel' => poa_h((string) (int) ($backlog['without_panel'] ?? 0)),
    'With seals' => poa_h((string) (int) ($backlog['with_seals'] ?? 0)),
    'Near expiry (≤2 left)' => poa_h((string) (int) ($backlog['within_two_epochs_of_expiry'] ?? 0)),
]);
echo '<p class="hint">' . poa_h((string) ($backlog['quorum_note'] ?? '')) . '</p>';
poa_panel_end();

poa_panel_start('Panel draw by close', 'Pending rows with a panel vs without at that close. Same pending snapshot as the Pending tab.');
echo '<div class="table-wrap"><table class="txs"><thead><tr>';
echo '<th>Close</th><th>Pending</th><th>With panel</th><th>Without panel</th><th>With seals</th>';
echo '</tr></thead><tbody>';
$panelRows = is_array($body['panel_by_close'] ?? null) ? $body['panel_by_close'] : [];
foreach ($panelRows as $p) {
    if (!is_array($p)) {
        continue;
    }
    $pan = is_array($p['panel'] ?? null) ? $p['panel'] : [];
    echo '<tr>';
    echo '<td>' . poa_h((string) ($p['epoch'] ?? '')) . '</td>';
    echo '<td>' . poa_h((string) (int) ($pan['pending'] ?? 0)) . '</td>';
    echo '<td>' . poa_h((string) (int) ($pan['with_panel'] ?? 0)) . '</td>';
    echo '<td>' . poa_h((string) (int) ($pan['without_panel'] ?? 0)) . '</td>';
    echo '<td>' . poa_h((string) (int) ($pan['with_seals'] ?? 0)) . '</td>';
    echo '</tr>';
}
echo '</tbody></table></div>';
poa_panel_end();

poa_panel_start('Establishment and payouts', 'Newly established agents and paid-address counts from the public close. Payout amounts are on close.rewards / final_payout.capped, not on the tx feed.');
echo '<div class="table-wrap"><table class="txs"><thead><tr>';
echo '<th>Close</th><th>Newly established</th><th>Paid (rewards &gt; 0)</th><th>final_payout.capped addresses</th>';
echo '</tr></thead><tbody>';
foreach ($payouts as $p) {
    if (!is_array($p)) {
        continue;
    }
    echo '<tr>';
    echo '<td>' . poa_h((string) ($p['epoch'] ?? '')) . '</td>';
    echo '<td>' . poa_h((string) (int) ($p['established_after'] ?? 0)) . '</td>';
    echo '<td>' . poa_h((string) (int) ($p['paid'] ?? 0)) . '</td>';
    echo '<td>' . poa_h((string) (int) ($p['final_payout_capped_addresses'] ?? 0)) . '</td>';
    echo '</tr>';
}
echo '</tbody></table></div>';
poa_panel_end();

poa_panel_start('Close-agreement', (string) ($agreement['note'] ?? ''));
poa_stats([
    'Closes checked' => poa_h((string) (int) ($agreement['closes_checked'] ?? 0)),
    'With missing keys' => poa_h((string) (int) ($agreement['closes_with_missing_keys'] ?? 0)),
]);
echo '<div class="table-wrap"><table class="txs"><thead><tr><th>Close</th><th>OK</th><th>Missing keys</th></tr></thead><tbody>';
foreach ((array) ($agreement['rows'] ?? []) as $a) {
    if (!is_array($a)) {
        continue;
    }
    $miss = is_array($a['keys_missing'] ?? null) ? $a['keys_missing'] : [];
    echo '<tr>';
    echo '<td>' . poa_h((string) ($a['epoch'] ?? '')) . '</td>';
    echo '<td>' . poa_h(!empty($a['ok']) ? 'yes' : 'no') . '</td>';
    echo '<td>' . poa_h($miss ? implode(', ', $miss) : '—') . '</td>';
    echo '</tr>';
}
echo '</tbody></table></div>';
poa_panel_end();

poa_layout_end($row + [
    'as_of' => isset($backlog['as_of_ts']) ? poa_format_ts($backlog['as_of_ts']) : null,
    'source' => 'public /api/epochs + /api/status + /api/explorer/txs + /api/anchors',
]);

<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

$row = poa_reports_row();
$body = is_array($row['body']) ? $row['body'] : [];
$err = $row['_error'] ?? null;
$net = is_array($body['network'] ?? null) ? $body['network'] : [];
$settlement = is_array($body['settlement'] ?? null) ? $body['settlement'] : [];
$chrono = poa_closes_chrono(is_array($settlement['rows'] ?? null) ? $settlement['rows'] : []);
$window = poa_reports_window($chrono);
$newestFirst = array_reverse($chrono);
$from = 0;
if (isset($_GET['from']) && preg_match('/^[0-9]{1,8}$/', (string) $_GET['from'])) {
    $from = (int) $_GET['from'];
}
$page = poa_reports_page($newestFirst, $from);
$latestEp = poa_latest_epoch($chrono);
$epochQ = $_GET['epoch'] ?? '';
$picked = null;
if (is_string($epochQ) && preg_match('/^[0-9]{1,8}$/', $epochQ)) {
    $picked = poa_find_close($chrono, (int) $epochQ);
}
if ($picked === null && $latestEp !== null) {
    $picked = poa_find_close($chrono, $latestEp);
}
$headline = poa_headline_from_closes($chrono);
$backlog = is_array($body['grading_backlog'] ?? null) ? $body['grading_backlog'] : [];
$agreement = is_array($body['close_agreement'] ?? null) ? $body['close_agreement'] : [];
$heat = poa_heatmap_still_pending($window);

$labels = [];
$settledSeries = [];
$pendingSeries = [];
$rateSeries = [];
foreach ($window as $c) {
    $labels[] = (string) ($c['epoch'] ?? '');
    $settledSeries[] = poa_int_or_null($c['settled'] ?? null);
    $pendingSeries[] = poa_int_or_null($c['still_pending'] ?? null);
    $rate = poa_panel_rate($c);
    $rateSeries[] = $rate === null ? null : round($rate * 100, 2);
}

poa_layout_start('Reports', 'reports');
echo '<h1>Reports</h1>';
echo '<p class="hint">Network-level views over the same public JSON as the rest of this explorer. Every figure cites its source. Missing data stays labeled. No wallet, no hosted API, no locked vs spendable split, no forecasts. Settlement lives here, not on its own page. Charts and the close table show the last ' . poa_h((string) POA_REPORTS_WINDOW) . ' closes. Cohort detail is one close at a time.</p>';

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

$prior = $headline['prior_epoch'];
$deltaHint = $prior === null
    ? 'No prior close to compare. Deltas stay unlabeled.'
    : ('Change vs close ' . $prior . '. Last close is ' . ($headline['epoch'] ?? '—') . '.');
poa_panel_start('Last close vs prior', $deltaHint . ' Source: cron snapshot of minepoa GET /api/epochs.');
poa_stats([
    'Settled' => poa_h(($headline['settled'] === null ? '—' : (string) $headline['settled']) . ' (' . poa_delta_label($headline['settled_delta']) . ')'),
    'Still pending' => poa_h(($headline['still_pending'] === null ? '—' : (string) $headline['still_pending']) . ' (' . poa_delta_label($headline['still_pending_delta']) . ')'),
    'Panel-draw rate' => poa_h(poa_rate_label($headline['panel_rate']) . ($headline['panel_rate_delta'] === null ? '' : (' (' . ($headline['panel_rate_delta'] >= 0 ? '+' : '') . sprintf('%.1fpp', $headline['panel_rate_delta'] * 100) . ')'))),
    'Newly established' => poa_h(($headline['established'] === null ? '—' : (string) $headline['established']) . ' (' . poa_delta_label($headline['established_delta']) . ')'),
]);
poa_panel_end();

poa_panel_start('Trends', 'Last ' . (string) count($window) . ' closes from the cron snapshot. SVG, no forecast. Settled and still-pending use separate axes.');
echo '<h3>Settled vs still-pending</h3>';
echo poa_svg_dual_line($labels, $settledSeries, $pendingSeries, 'Settled', 'Still pending');
echo '<h3>Backlog (still pending)</h3>';
echo poa_svg_single_line($labels, $pendingSeries, 'Still pending');
echo '<h3>Panel-draw rate</h3>';
echo poa_svg_single_line($labels, $rateSeries, 'With panel %');
poa_panel_end();

poa_panel_start(
    'Settlement by close',
    (string) (($settlement['source'] ?? '') . '. Newest first. Window ' . POA_REPORTS_WINDOW . '. Click a close for its birth-cohort table. Sealed / settled / paid stay three columns. Panel-draw and payouts are on this table so they do not grow as separate lists.')
);
echo '<div class="table-wrap"><table class="txs"><thead><tr>';
echo '<th>Close</th><th>Time</th><th>Sealed</th><th>Settled</th><th>Δ settled</th><th>Passed</th><th>Failed</th><th>Paid</th><th>Still pending</th><th>Δ pending</th><th>With panel</th><th>Established</th>';
echo '</tr></thead><tbody>';
if (!$page) {
    echo '<tr><td colspan="12">No close records.</td></tr>';
}
$byEpoch = [];
foreach ($chrono as $c) {
    $byEpoch[(int) ($c['epoch'] ?? -1)] = $c;
}
foreach ($page as $c) {
    $ep = (int) ($c['epoch'] ?? -1);
    $prev = $byEpoch[$ep - 1] ?? null;
    $dSettled = poa_delta(poa_int_or_null($c['settled'] ?? null), is_array($prev) ? poa_int_or_null($prev['settled'] ?? null) : null);
    $dPend = poa_delta(poa_int_or_null($c['still_pending'] ?? null), is_array($prev) ? poa_int_or_null($prev['still_pending'] ?? null) : null);
    $pan = is_array($c['panel'] ?? null) ? $c['panel'] : [];
    $qs = 'epoch=' . rawurlencode((string) $ep);
    if ($from > 0) {
        $qs .= '&from=' . rawurlencode((string) $from);
    }
    echo '<tr>';
    echo '<td class="mono"><a href="reports.php?' . poa_h($qs) . '">' . poa_h((string) $ep) . '</a></td>';
    echo '<td>' . poa_h(poa_format_ts($c['ts'] ?? null)) . '</td>';
    echo '<td>' . poa_h((string) (int) ($c['sealed'] ?? 0)) . '</td>';
    echo '<td>' . poa_h((string) (int) ($c['settled'] ?? 0)) . '</td>';
    echo '<td>' . poa_h(poa_delta_label($dSettled)) . '</td>';
    echo '<td>' . poa_h((string) (int) ($c['passed'] ?? 0)) . '</td>';
    echo '<td>' . poa_h((string) (int) ($c['failed'] ?? 0)) . '</td>';
    echo '<td>' . poa_h((string) (int) ($c['paid'] ?? 0)) . '</td>';
    echo '<td>' . poa_h((string) (int) ($c['still_pending'] ?? 0)) . '</td>';
    echo '<td>' . poa_h(poa_delta_label($dPend)) . '</td>';
    echo '<td>' . poa_h(isset($pan['with_panel']) ? (string) (int) $pan['with_panel'] : '—') . '</td>';
    echo '<td>' . poa_h((string) (int) ($c['established_after'] ?? 0)) . '</td>';
    echo '</tr>';
}
echo '</tbody></table></div>';
$totalCloses = count($newestFirst);
if ($from > 0 || ($from + POA_REPORTS_WINDOW) < $totalCloses) {
    echo '<p class="hint">Showing ' . poa_h((string) ($from + 1)) . '–' . poa_h((string) min($from + count($page), $totalCloses)) . ' of ' . poa_h((string) $totalCloses) . ' closes.';
    if ($from > 0) {
        $back = max(0, $from - POA_REPORTS_WINDOW);
        echo ' <a href="reports.php?from=' . poa_h((string) $back) . '">Newer</a>';
    }
    if (($from + POA_REPORTS_WINDOW) < $totalCloses) {
        echo ' <a href="reports.php?from=' . poa_h((string) ($from + POA_REPORTS_WINDOW)) . '">Older</a>';
    }
    echo '</p>';
}
poa_panel_end();

poa_panel_start(
    'Still-pending by birth cohort',
    'Grid for the same last-' . (string) POA_REPORTS_WINDOW . ' window. Color is still-pending at that close (hotter = more leftover). A missing cell is unlabeled — not filled with zero. Click a close heading or a cell to open that close’s table.'
);
if (!$heat['closes']) {
    echo '<p class="hint">No cohort grid yet.</p>';
} else {
    echo '<div class="table-wrap"><table class="heat"><thead><tr><th>Birth</th>';
    foreach ($heat['closes'] as $ep) {
        echo '<th><a href="reports.php?epoch=' . poa_h((string) (int) $ep) . '">' . poa_h((string) (int) $ep) . '</a></th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($heat['births'] as $b) {
        $bk = $b === 'unknown' ? 'unknown' : (string) (int) $b;
        echo '<tr><th>' . poa_h($bk) . '</th>';
        foreach ($heat['closes'] as $ep) {
            $ep = (int) $ep;
            if (!isset($heat['cells'][$bk][$ep])) {
                echo '<td class="heat-miss">—</td>';
                continue;
            }
            $v = (int) $heat['cells'][$bk][$ep];
            $fill = poa_heat_fill($v, (int) $heat['max']);
            echo '<td class="heat-cell"><a href="reports.php?epoch=' . poa_h((string) $ep) . '" style="background:' . poa_h($fill) . '">' . poa_h((string) $v) . '</a></td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}
poa_panel_end();

$pickedEp = is_array($picked) ? (int) ($picked['epoch'] ?? -1) : -1;
poa_panel_start(
    'Cohorts at close ' . ($pickedEp >= 0 ? (string) $pickedEp : '—'),
    is_array($picked)
        ? ((string) ($picked['source'] ?? '') . ' · ' . (string) ($picked['birth_rule'] ?? ''))
        : 'Pick a close from the table or grid.'
);
if (!is_array($picked)) {
    echo '<p class="hint">No close selected.</p>';
} else {
    $cohorts = is_array($picked['cohorts'] ?? null) ? $picked['cohorts'] : [];
    echo '<p class="hint">Passed / failed / settled / still-pending for this close only. Default is the latest close. <a href="reports.php?epoch=8">Close 8</a> is the Copperwick check (settled 345, birth-epoch 3 settled 0).</p>';
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

$missingRows = [];
foreach ((array) ($agreement['rows'] ?? []) as $a) {
    if (is_array($a) && empty($a['ok'])) {
        $missingRows[] = $a;
    }
}
poa_panel_start('Close-agreement', (string) ($agreement['note'] ?? ''));
poa_stats([
    'Closes checked' => poa_h((string) (int) ($agreement['closes_checked'] ?? 0)),
    'With missing keys' => poa_h((string) (int) ($agreement['closes_with_missing_keys'] ?? 0)),
]);
if (!$missingRows) {
    echo '<p class="hint">No missing close-record keys on the snapshot. Rows stay hidden until something is actually missing.</p>';
} else {
    echo '<div class="table-wrap"><table class="txs"><thead><tr><th>Close</th><th>Missing keys</th></tr></thead><tbody>';
    foreach ($missingRows as $a) {
        $miss = is_array($a['keys_missing'] ?? null) ? $a['keys_missing'] : [];
        echo '<tr><td>' . poa_h((string) ($a['epoch'] ?? '')) . '</td><td>' . poa_h($miss ? implode(', ', $miss) : '—') . '</td></tr>';
    }
    echo '</tbody></table></div>';
}
poa_panel_end();

poa_layout_end($row + [
    'as_of' => isset($backlog['as_of_ts']) ? poa_format_ts($backlog['as_of_ts']) : null,
    'source' => 'public /api/epochs + /api/status + /api/explorer/txs + /api/anchors (cron snapshot)',
]);

<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

$addr = trim((string) ($_GET['a'] ?? ''));
$withoutOnly = isset($_GET['without_panel']) && $_GET['without_panel'] !== '0' && $_GET['without_panel'] !== '';
$limit = POA_EXPLORER_PENDING_PAGE_LIMIT;
$row = poa_pending_row($addr, $withoutOnly, $limit);
$body = is_array($row['body']) ? $row['body'] : [];
$err = $row['_error'] ?? null;
$rows = is_array($body['rows'] ?? null) ? $body['rows'] : [];

poa_layout_start('Pending', 'pending');
echo '<h1>Pending</h1>';
echo '<p class="hint">Panel size and age come from the latest <em>closed</em> epoch’s <code>basis.pending</code> on minepoa <code>/api/epochs</code>. That is a snapshot, not a live jury draw. Author is filled only when that answer still appears in the current <code>/api/challenge-index</code> (older leftover rows often have no author in public JSON). Public JSON does not include grades received or quorum on those pending rows — those columns stay blank rather than guessed.</p>';

if ($err) {
    poa_error_box((string) $err);
}

$asOf = $body['as_of_epoch'] ?? null;
poa_stats([
    'As of close' => poa_h($asOf === null ? '—' : (string) $asOf),
    'Pending rows' => poa_h((string) ($body['total'] ?? 0)),
    'With panel' => poa_h((string) ($body['with_panel'] ?? 0)),
    'Without panel' => poa_h((string) ($body['without_panel'] ?? 0)),
    'Settled this close' => poa_h((string) ($body['settled_this_close'] ?? 0)),
]);
echo '<p class="hint">' . poa_h((string) ($body['live_panel_note'] ?? '')) . '</p>';

echo '<form class="filter" method="get" action="pending.php">';
echo '<label for="pend-a">Account <input id="pend-a" name="a" type="search" placeholder="poa1…" value="' . poa_h($addr) . '" autocomplete="off"></label>';
echo '<label class="check"><input type="checkbox" name="without_panel" value="1"' . ($withoutOnly ? ' checked' : '') . '> Without panel only</label>';
echo '<button type="submit">Filter</button>';
echo '</form>';

if ($addr !== '' && !poa_is_address($addr)) {
    poa_error_box('Pass a PoA address starting with poa1, or leave the filter blank.');
}

poa_panel_start(
    $addr !== '' ? 'Pending for this account' : 'Pending list',
    'Showing ' . (int) ($body['shown'] ?? 0) . ' of ' . (int) ($body['matched'] ?? $body['total'] ?? 0) . ' matching rows (cap ' . (int) ($body['limit'] ?? $limit) . '). Age is `since` in epochs against a 16-epoch expiry.'
);

echo '<div class="table-wrap"><table class="txs"><thead><tr>';
echo '<th>Answer</th><th>Author</th><th>Kind</th><th>Panel size</th><th>Panel</th><th>Grades</th><th>Quorum</th><th>Age</th><th>Expiry</th>';
echo '</tr></thead><tbody>';
if (!$rows) {
    echo '<tr><td colspan="9">No pending rows in this snapshot for the current filter.</td></tr>';
}
foreach ($rows as $p) {
    if (!is_array($p)) {
        continue;
    }
    $panelBits = [];
    foreach ((array) ($p['panel'] ?? []) as $g) {
        $panelBits[] = poa_addr_link((string) $g);
    }
    $since = $p['since'];
    $expiry = (int) ($p['expiry_epochs'] ?? POA_EXPLORER_EPOCH_EXPIRY);
    $left = is_int($since) || is_numeric($since) ? ($expiry - (int) $since) : null;
    echo '<tr>';
    echo '<td class="mono">' . poa_h(poa_short_id((string) ($p['answer'] ?? ''), 8)) . '</td>';
    echo '<td>' . poa_addr_link((string) ($p['author'] ?? '')) . '</td>';
    echo '<td>' . poa_h((string) ($p['kind'] ?? '—')) . '</td>';
    echo '<td>' . poa_h((string) (int) ($p['panel_size'] ?? 0)) . '</td>';
    echo '<td>' . ($panelBits ? implode(' ', $panelBits) : '—') . '</td>';
    echo '<td title="' . poa_h((string) ($p['grades_note'] ?? '')) . '">—</td>';
    echo '<td title="' . poa_h((string) ($p['quorum_note'] ?? '')) . '">—</td>';
    echo '<td>' . poa_h($since === null || $since === '' ? '—' : ((string) $since . ' epochs')) . '</td>';
    echo '<td>' . poa_h($left === null ? '—' : ((string) $left . ' left / ' . (string) $expiry)) . '</td>';
    echo '</tr>';
}
echo '</tbody></table></div>';
poa_panel_end();

poa_layout_end($row + [
    'as_of' => isset($body['as_of_ts']) ? poa_format_ts($body['as_of_ts']) : null,
    'source' => $body['source'] ?? null,
]);

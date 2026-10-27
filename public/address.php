<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

$addr = trim((string) ($_GET['a'] ?? ''));
$row = poa_address($addr);
$body = is_array($row['body']) ? $row['body'] : [];
$err = $row['_error'] ?? null;

poa_layout_start($addr !== '' ? poa_short_id($addr, 8) : 'Address', 'home');
echo '<h1>Account</h1>';

if ($addr === '' || !poa_is_address($addr)) {
    poa_error_box('Pass a PoA address starting with poa1.');
    poa_layout_end($row);
    exit;
}

if ($err) {
    poa_error_box((string) $err);
}

if ($body && !isset($body['error'])) {
    $balAsOf = $body['balance_as_of']['checkpoint_height'] ?? null;
    poa_panel_start('Account');
    poa_kv([
        'Address' => '<span class="mono">' . poa_h((string) ($body['address'] ?? $addr)) . '</span>',
        'Ledger balance' => poa_h(poa_format_amount($body['balance'] ?? null)) . ' POA',
        'Balance as of checkpoint' => poa_h($balAsOf === null ? '—' : (string) $balAsOf),
        'Checkpoint height' => poa_h(isset($body['checkpoint_height']) ? (string) $body['checkpoint_height'] : '—'),
    ]);
    echo '<p class="hint">Public JSON does not split locked vs spendable. Some credit activity may appear only as this balance, not as a row below.</p>';
    poa_panel_end();

    $g = is_array($body['grading'] ?? null) ? $body['grading'] : [];
    $pendHref = 'pending.php?a=' . rawurlencode($addr);
    poa_panel_start('Grading and pending', 'Last closed epoch snapshot plus this account’s grade-sealed txs. Panel is not a live draw.');
    poa_stats([
        'As of close' => poa_h(($g['as_of_epoch'] ?? null) === null ? '—' : (string) $g['as_of_epoch']),
        'Pending assignments' => poa_h((string) (int) ($g['pending_count'] ?? 0)),
        'Without panel' => poa_h((string) (int) ($g['without_panel_count'] ?? 0)),
        'Answers as author (index)' => poa_h((string) (int) ($g['answers_as_author_count'] ?? 0)),
        'Settled as author this close' => poa_h((string) (int) ($g['settled_as_author_this_close'] ?? 0)),
        'Settled via answer txid' => poa_h((string) (int) ($g['settled_by_answer_txid_count'] ?? 0)),
        'Jury rows this close' => poa_h((string) (int) ($g['jury_rows_this_close'] ?? 0)),
        'Graders served this close' => poa_h(($g['graders_served_this_close'] ?? null) === null ? '—' : (string) $g['graders_served_this_close']),
        'Grade-sealed txs on this page' => poa_h((string) count($g['grade_sealed_txs'] ?? [])),
    ]);
    echo '<p class="hint"><a href="' . poa_h($pendHref) . '">Open pending tab for this account</a>. Pending rows here include last-close matches by challenge-index author <em>or</em> by this account’s challenge transaction ids. Settled via answer txid walks every public close’s <code>basis.settled</code> and joins <code>answer</code> to those same tx ids. ' . poa_h((string) ($g['live_panel_note'] ?? '')) . '</p>';
    if (!empty($g['pending'])) {
        echo '<div class="table-wrap"><table class="txs"><thead><tr><th>Answer tx</th><th>Kind</th><th>Panel size</th><th>Seals</th><th>Age</th></tr></thead><tbody>';
        foreach ($g['pending'] as $p) {
            if (!is_array($p)) {
                continue;
            }
            $gLabel = poa_grades_label($p['grades'] ?? null, $p['grades_count'] ?? null, $p['grades_note'] ?? '');
            echo '<tr><td>' . poa_tx_link((string) ($p['answer_txid'] ?? $p['answer'] ?? '')) . '</td>';
            echo '<td>' . poa_h((string) ($p['kind'] ?? '—')) . '</td>';
            echo '<td>' . poa_h((string) (int) ($p['panel_size'] ?? 0)) . '</td>';
            echo '<td>' . poa_h($gLabel) . '</td>';
            echo '<td>' . poa_h($p['since'] === null || $p['since'] === '' ? '—' : ((string) $p['since'] . ' epochs')) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    if (!empty($g['settled_by_answer_txid'])) {
        echo '<h3>Settled rows joined by answer txid</h3>';
        echo '<div class="table-wrap"><table class="txs"><thead><tr><th>Answer tx</th><th>Close</th><th>Author on close</th><th>Passed</th></tr></thead><tbody>';
        foreach ($g['settled_by_answer_txid'] as $s) {
            if (!is_array($s)) {
                continue;
            }
            echo '<tr><td>' . poa_tx_link((string) ($s['answer_txid'] ?? '')) . '</td>';
            echo '<td>' . poa_h(($s['close_epoch'] ?? null) === null ? '—' : (string) $s['close_epoch']) . '</td>';
            echo '<td>' . poa_addr_link((string) ($s['from'] ?? '')) . '</td>';
            echo '<td>' . poa_h(isset($s['passed']) ? ($s['passed'] ? 'yes' : 'no') : '—') . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    if (!empty($g['jury_as_grader'])) {
        echo '<h3>As grader (this close)</h3>';
        echo '<div class="table-wrap"><table class="txs"><thead><tr><th>Answer</th><th>Author</th><th>Passed</th><th>Aligned</th></tr></thead><tbody>';
        foreach ($g['jury_as_grader'] as $j) {
            if (!is_array($j)) {
                continue;
            }
            echo '<tr><td>' . poa_tx_link((string) ($j['answer_txid'] ?? $j['answer'] ?? '')) . '</td>';
            echo '<td>' . poa_addr_link((string) ($j['from'] ?? '')) . '</td>';
            echo '<td>' . poa_h(!empty($j['passed']) ? 'yes' : 'no') . '</td>';
            echo '<td>' . poa_h(isset($j['aligned']) ? ($j['aligned'] ? 'yes' : 'no') : '—') . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    poa_panel_end();

    poa_panel_start('Transactions');
    poa_tx_table(poa_tx_list_from_address($body));
    poa_panel_end();
}

poa_layout_end($row + [
    'as_of' => $body['as_of'] ?? null,
    'source' => $body['source'] ?? null,
]);

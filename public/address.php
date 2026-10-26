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

    poa_panel_start('Transactions');
    poa_tx_table(poa_tx_list_from_address($body));
    poa_panel_end();
}

poa_layout_end($row + [
    'as_of' => $body['as_of'] ?? null,
    'source' => $body['source'] ?? null,
]);

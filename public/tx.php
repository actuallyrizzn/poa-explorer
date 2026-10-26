<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

$id = trim((string) ($_GET['id'] ?? ''));
$row = poa_tx($id);
$body = is_array($row['body']) ? $row['body'] : [];
$err = $row['_error'] ?? null;

poa_layout_start($id !== '' ? poa_short_id($id, 10) : 'Transaction', 'home');
echo '<h1>Transaction</h1>';

if ($id === '' || !poa_is_tx_id($id)) {
    poa_error_box('Pass a 64-character transaction id.');
    poa_layout_end($row);
    exit;
}

if ($err) {
    poa_error_box((string) $err);
}

if ($body && !isset($body['error'])) {
    $from = (string) ($body['from_addr'] ?? $body['from'] ?? '');
    $to = (string) ($body['to_addr'] ?? $body['to'] ?? '');
    $parents = $body['body']['parents'] ?? [];
    $parentHtml = '—';
    if (is_array($parents) && $parents) {
        $bits = [];
        foreach ($parents as $p) {
            $bits[] = poa_tx_link((string) $p);
        }
        $parentHtml = implode('<br>', $bits);
    }

    poa_panel_start('Transaction');
    poa_kv([
        'Id' => '<span class="mono">' . poa_h((string) ($body['id'] ?? $id)) . '</span>',
        'Type' => poa_h((string) ($body['type'] ?? '—')),
        'From' => poa_addr_link($from),
        'To' => poa_addr_link($to),
        'Amount' => poa_h(poa_format_amount($body['amount'] ?? null)),
        'Epoch' => poa_h($body['epoch'] === null || $body['epoch'] === '' ? '—' : (string) $body['epoch']),
        'Attestations' => poa_h(isset($body['atts']) ? (string) $body['atts'] : '—'),
        'Finality' => poa_h(poa_finality_label($body)),
        'Checkpoint height' => poa_h(isset($body['checkpoint_height']) ? (string) $body['checkpoint_height'] : '—'),
        'Time' => poa_h(poa_format_ts($body['ts'] ?? null)),
        'Memo' => poa_h((string) (($body['memo'] ?? '') === '' ? '—' : $body['memo'])),
        'Parents' => $parentHtml,
    ]);
    poa_panel_end();

    $inner = $body['body'] ?? null;
    if (is_array($inner)) {
        poa_panel_start('Body');
        echo '<pre class="blob">' . poa_h(json_encode($inner, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre>';
        poa_panel_end();
    }
}

poa_layout_end($row + [
    'as_of' => $body['as_of'] ?? null,
    'source' => $body['source'] ?? null,
]);

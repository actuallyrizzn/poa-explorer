<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/poa.php';

$q = trim((string) ($_GET['q'] ?? ''));

if ($q === '') {
    header('Location: index.php', true, 302);
    exit;
}

if (poa_is_address($q)) {
    header('Location: address.php?a=' . rawurlencode($q), true, 302);
    exit;
}

if (poa_is_tx_id($q)) {
    header('Location: tx.php?id=' . rawurlencode(strtolower($q)), true, 302);
    exit;
}

if (poa_is_height($q)) {
    header('Location: checkpoint.php?height=' . rawurlencode($q), true, 302);
    exit;
}

require_once __DIR__ . '/includes/layout.php';
poa_layout_start('Search', 'home');
echo '<h1>Search</h1>';
poa_error_box('That does not look like a PoA address, a 64-character transaction id, or a checkpoint height.');
echo '<p><a href="index.php">Back to home</a></p>';
poa_layout_end(['_cache' => null]);

<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/poa.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
$row = poa_status();
echo json_encode([
    'ok' => empty($row['_error']),
    'error' => $row['_error'],
    'cache' => $row['_cache'],
    'body' => $row['body'],
], JSON_UNESCAPED_SLASHES);

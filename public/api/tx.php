<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/poa.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
$id = trim((string) ($_GET['id'] ?? ''));
$row = poa_tx($id);
http_response_code($row['_error'] ? 400 : 200);
echo json_encode([
    'ok' => empty($row['_error']),
    'error' => $row['_error'],
    'cache' => $row['_cache'],
    'body' => $row['body'],
], JSON_UNESCAPED_SLASHES);

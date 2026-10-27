<?php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$query = [];
parse_str((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY), $query);
header('Content-Type: application/json; charset=UTF-8');

$ok = static function ($body) {
    echo json_encode(['ok' => true, 'error' => null, 'cache' => 'fresh', 'version' => '0.3.1', 'body' => $body], JSON_UNESCAPED_SLASHES);
};

if ($path === '/api/status.php') {
    $ok(['chain_id' => 'poa-fixture', 'checkpoint' => ['height' => 9]]);
    exit;
}
if ($path === '/api/txs.php') {
    $ok([['id' => 'aa', 'type' => 'challenge']]);
    exit;
}
if ($path === '/api/tx.php') {
    $id = (string) ($query['id'] ?? '');
    if ($id === 'bad') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'not a transaction id', 'cache' => 'miss', 'body' => null]);
        exit;
    }
    $ok(['id' => $id, 'type' => 'challenge']);
    exit;
}
if ($path === '/api/address.php') {
    $ok(['address' => (string) ($query['a'] ?? ''), 'balance' => '1']);
    exit;
}
if ($path === '/api/anchors.php') {
    $ok([['height' => 9, 'chain' => 'base-mainnet']]);
    exit;
}
if ($path === '/api/epochs.php') {
    $ok([['epoch' => 0]]);
    exit;
}
if ($path === '/api/challenges.php') {
    $ok(['answers' => []]);
    exit;
}
if ($path === '/api/changelog.php') {
    $ok(['current' => '0.3.1', 'entries' => []]);
    exit;
}
if ($path === '/api/close.php') {
    $ok(['epoch' => (int) ($query['epoch'] ?? 0), 'ts' => 1, 'pool' => '1', 'note' => 'x', 'basis' => [], 'weights' => [], 'challenges' => [], 'propagation' => [], 'rewards' => []]);
    exit;
}
if ($path === '/api/pending.php') {
    $ok(['total' => 0, 'rows' => [], 'as_of_epoch' => 0]);
    exit;
}
http_response_code(404);
echo json_encode(['ok' => false, 'error' => 'missing', 'cache' => 'miss', 'body' => null]);

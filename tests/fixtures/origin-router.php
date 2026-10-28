<?php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
header('Content-Type: application/json; charset=UTF-8');

if ($path === '/empty') {
    http_response_code(200);
    exit;
}
if ($path === '/badjson') {
    header('Content-Type: text/plain');
    echo 'not-json';
    exit;
}
if ($path === '/http400') {
    http_response_code(400);
    echo '{"oops":true}';
    exit;
}
if ($path === '/api/status') {
    http_response_code(500);
    echo '{"error":"nope"}';
    exit;
}
if ($path === '/chain/poa-mainnet-1/api/status') {
    echo json_encode(['chain_id' => 'poa-fixture', 'checkpoint' => ['height' => 7]], JSON_UNESCAPED_SLASHES);
    exit;
}
if ($path === '/api/explorer/txs') {
    echo json_encode(['valid' => [['id' => 'abc', 'type' => 'x']]], JSON_UNESCAPED_SLASHES);
    exit;
}
if ($path === '/api/anchors') {
    echo '[]';
    exit;
}
if ($path === '/api/epochs') {
    echo json_encode([[
        'epoch' => 0,
        'ts' => 1790000000,
        'pool' => '1',
        'note' => 'fixture',
        'basis' => [
            'pending' => [
                'aa' => ['kind' => 'short', 'panel' => [], 'originalPassed' => false, 'since' => 1],
            ],
            'settled' => [],
            'graders_served' => [],
        ],
        'weights' => [],
        'challenges' => [],
        'propagation' => [],
        'rewards' => [],
    ]], JSON_UNESCAPED_SLASHES);
    exit;
}
if ($path === '/api/swaps') {
    echo json_encode([['id' => 's1', 'pair' => 'SOL|solana']], JSON_UNESCAPED_SLASHES);
    exit;
}
if ($path === '/api/book') {
    echo json_encode([
        'state' => 'available',
        'swaps_live' => true,
        'writes' => null,
        'pairs' => [[
            'key' => 'SOL|solana',
            'asset' => 'SOL',
            'chain' => 'solana',
            'chain_name' => 'Solana',
            'best_ask' => 0.15,
            'last' => 0.4,
            'last_ts' => 1792791356,
            'asks' => [['px' => 0.15, 'poa' => 1000, 'want' => 0.00015]],
        ]],
        'usd' => ['src' => 'CoinGecko', 'rates' => ['SOL' => 100, 'ETH' => 2000]],
    ], JSON_UNESCAPED_SLASHES);
    exit;
}
if ($path === '/api/v3/simple/price') {
    echo json_encode(['solana' => ['usd' => 101.91], 'ethereum' => ['usd' => 2350]], JSON_UNESCAPED_SLASHES);
    exit;
}
if ($path === '/api/challenge-index') {
    echo json_encode([
        'chain_id' => 'poa-fixture',
        'answers' => [
            'aa' => ['epoch' => 0, 'author' => 'poa1AoECFMm8ge4C9dx8x7LzBxvaByGsZagiT6HY1GYwBa5W', 'source' => 'live'],
        ],
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

http_response_code(404);
echo '{"error":"missing"}';

<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

$base = 'https://poa.explorer.zero1.network';
echo json_encode([
    'openapi' => '3.0.3',
    'info' => [
        'title' => 'PoA Explorer API',
        'version' => '0.2.0',
        'description' => 'Read-only JSON for the Proof of Agent ledger. No wallet. No keys. Never /api/hosted.',
        'license' => [
            'name' => 'AGPL-3.0',
            'url' => 'https://www.gnu.org/licenses/agpl-3.0.html',
        ],
    ],
    'servers' => [['url' => $base]],
    'paths' => [
        '/api/status.php' => ['get' => ['summary' => 'Chain tip and checkpoint', 'operationId' => 'status']],
        '/api/txs.php' => ['get' => ['summary' => 'Recent transactions', 'operationId' => 'recentTxs']],
        '/api/tx.php' => ['get' => [
            'summary' => 'One transaction',
            'operationId' => 'tx',
            'parameters' => [['name' => 'id', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string']]],
        ]],
        '/api/address.php' => ['get' => [
            'summary' => 'One account',
            'operationId' => 'address',
            'parameters' => [['name' => 'a', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string']]],
        ]],
        '/api/anchors.php' => ['get' => ['summary' => 'External checkpoint anchors', 'operationId' => 'anchors']],
        '/api/epochs.php' => ['get' => ['summary' => 'Closed epochs', 'operationId' => 'epochs']],
        '/api/challenges.php' => ['get' => ['summary' => 'Challenge index (large)', 'operationId' => 'challenges']],
    ],
    'x-envelope' => ['ok' => 'boolean', 'error' => 'string|null', 'cache' => 'fresh|hit|stale|miss', 'body' => 'origin JSON'],
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

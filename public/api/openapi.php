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
        'version' => '0.5.1',
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
        '/api/epochs.php' => ['get' => ['summary' => 'Closed epochs (same keys as minepoa /api/epochs)', 'operationId' => 'epochs']],
        '/api/close.php' => ['get' => [
            'summary' => 'One close record by epoch',
            'operationId' => 'close',
            'parameters' => [['name' => 'epoch', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string']]],
        ]],
        '/api/pending.php' => ['get' => [
            'summary' => 'Last-close pending snapshot (panel, age, seals, answer txid)',
            'operationId' => 'pending',
            'parameters' => [
                ['name' => 'a', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
                ['name' => 'without_panel', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
            ],
        ]],
        '/api/reports.php' => ['get' => ['summary' => 'Network reports (public data only)', 'operationId' => 'reports']],
        '/api/changelog.php' => ['get' => ['summary' => 'API changelog', 'operationId' => 'changelog']],
        '/api/challenges.php' => ['get' => ['summary' => 'Challenge index (large)', 'operationId' => 'challenges']],
    ],
    'x-envelope' => ['ok' => 'boolean', 'error' => 'string|null', 'cache' => 'fresh|hit|stale|miss', 'version' => 'semver', 'body' => 'origin JSON'],
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

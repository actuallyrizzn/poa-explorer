<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function poa_h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function poa_is_tx_id(string $q): bool
{
    return (bool) preg_match('/^[a-fA-F0-9]{64}$/', $q);
}

function poa_is_address(string $q): bool
{
    return (bool) preg_match('/^poa1[A-Za-z0-9]{20,90}$/', $q);
}

function poa_is_height(string $q): bool
{
    return (bool) preg_match('/^[0-9]{1,12}$/', $q);
}

function poa_format_ts($ts): string
{
    if ($ts === null || $ts === '') {
        return '—';
    }
    $n = (int) $ts;
    if ($n <= 0) {
        return '—';
    }
    return gmdate('Y-m-d H:i:s', $n) . ' UTC';
}

function poa_format_amount($amount): string
{
    if ($amount === null || $amount === '') {
        return '—';
    }
    return (string) $amount;
}

function poa_short_id(string $id, int $keep = 8): string
{
    $id = trim($id);
    if (strlen($id) <= ($keep * 2) + 1) {
        return $id;
    }
    return substr($id, 0, $keep) . '…' . substr($id, -$keep);
}

function poa_wrap(array $extra): array
{
    return $extra + [
        '_error' => null,
        '_cache' => 'miss',
        '_path' => '',
        '_status' => 0,
        'body' => null,
    ];
}

function poa_json_get(string $path): array
{
    $path = '/' . ltrim($path, '/');
    if (str_starts_with($path, '/api/hosted')) {
        return poa_wrap([
            '_error' => 'hosted API is out of scope for this explorer',
            '_path' => $path,
        ]);
    }

    $cacheDir = poa_cache_dir();
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0770, true);
    }
    $cacheFile = $cacheDir . '/' . hash('sha256', $path) . '.json';
    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < POA_EXPLORER_CACHE_TTL) {
        $cached = file_get_contents($cacheFile);
        if ($cached !== false && $cached !== '') {
            $decoded = json_decode($cached, true);
            if (array_key_exists('body', (array) $decoded)) {
                $decoded['_cache'] = 'hit';
                return $decoded;
            }
        }
    }

    $url = poa_origin() . $path;
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => POA_EXPLORER_TIMEOUT,
            'header' => "User-Agent: " . POA_EXPLORER_UA . "\r\nAccept: application/json\r\n",
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    $status = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $status = (int) $m[1];
    }

    $stale = static function () use ($cacheFile, $path, $status): array {
        if (is_file($cacheFile)) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached) && array_key_exists('body', $cached)) {
                $cached['_cache'] = 'stale';
                $cached['_error'] = $cached['_error'] ?? 'origin unreachable; serving last-good cache';
                $cached['_status'] = $status;
                return $cached;
            }
        }
        return poa_wrap([
            '_error' => 'origin unreachable',
            '_status' => $status,
            '_path' => $path,
        ]);
    };

    if ($raw === false || $raw === '') {
        return $stale();
    }

    $decoded = json_decode($raw, true);
    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
        return poa_wrap([
            '_error' => 'origin returned non-JSON',
            '_status' => $status,
            '_path' => $path,
        ]);
    }

    $err = null;
    if ($status >= 400) {
        $err = is_array($decoded) && isset($decoded['error'])
            ? (string) $decoded['error']
            : 'origin HTTP ' . $status;
    }

    $out = poa_wrap([
        '_error' => $err,
        '_cache' => 'fresh',
        '_path' => $path,
        '_status' => $status,
        'body' => $decoded,
    ]);
    if ($err === null) {
        @file_put_contents($cacheFile, json_encode($out, JSON_UNESCAPED_SLASHES));
    }
    return $out;
}

function poa_status(): array
{
    $row = poa_json_get('/api/status');
    $chain = poa_json_get('/chain/poa-mainnet-1/api/status');
    $body = is_array($row['body']) ? $row['body'] : [];
    $chainBody = is_array($chain['body']) ? $chain['body'] : [];
    if (isset($chainBody['chain_id'])) {
        $body['chain_id'] = $chainBody['chain_id'];
    }
    if (isset($chainBody['checkpoint']) && is_array($chainBody['checkpoint'])) {
        $body['checkpoint'] = $chainBody['checkpoint'];
    }
    $row['body'] = $body;
    if ($row['_error'] && !$chain['_error'] && $body) {
        $row['_error'] = null;
        $row['_cache'] = $chain['_cache'];
    }
    return $row;
}

function poa_recent_txs(): array
{
    $row = poa_json_get('/api/explorer/txs');
    $body = $row['body'];
    if (is_array($body) && isset($body[0]) && is_array($body[0])) {
        return array_slice($body, 0, POA_EXPLORER_RECENT_TX_LIMIT);
    }
    if (is_array($body) && isset($body['valid']) && is_array($body['valid'])) {
        return array_slice($body['valid'], 0, POA_EXPLORER_RECENT_TX_LIMIT);
    }
    return [];
}

function poa_tx(string $id): array
{
    if (!poa_is_tx_id($id)) {
        return poa_wrap(['_error' => 'not a transaction id']);
    }
    return poa_json_get('/api/explorer/tx/' . strtolower($id));
}

function poa_address(string $addr): array
{
    if (!poa_is_address($addr)) {
        return poa_wrap(['_error' => 'not a PoA address']);
    }
    return poa_json_get('/api/explorer/address/' . $addr);
}

function poa_anchors(): array
{
    $row = poa_json_get('/api/anchors');
    $body = $row['body'];
    if (!is_array($body) || !isset($body[0]) || !is_array($body[0])) {
        return [];
    }
    usort($body, static function ($a, $b) {
        return ((int) ($b['height'] ?? 0)) <=> ((int) ($a['height'] ?? 0));
    });
    return array_slice($body, 0, POA_EXPLORER_ANCHOR_LIMIT);
}

function poa_tx_list_from_address(array $addrBody): array
{
    $txs = $addrBody['txs'] ?? [];
    return is_array($txs) ? $txs : [];
}

function poa_finality_label(array $row): string
{
    $final = $row['finality']['final'] ?? $row['final'] ?? null;
    $isFinal = $final === true || $final === 1 || $final === '1';
    if ($isFinal) {
        $via = $row['finality']['via'] ?? null;
        $h = $row['finality']['height'] ?? $row['checkpointed']['height'] ?? null;
        if ($via && $h) {
            return 'final via ' . $via . ' @ ' . $h;
        }
        return 'final';
    }
    return 'unfinal';
}

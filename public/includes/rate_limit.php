<?php
declare(strict_types=1);

/**
 * Cheap fixed-window rate limits. Locks are held only for the counter update
 * (milliseconds) — never across origin I/O.
 */

const POA_RL_TX_HTML = ['max' => 12, 'window' => 60];
const POA_RL_ADDRESS_HTML = ['max' => 12, 'window' => 60];
const POA_RL_HEALTH = ['max' => 6, 'window' => 60];
const POA_RL_TX_API = ['max' => 60, 'window' => 60];
const POA_RL_ADDRESS_API = ['max' => 60, 'window' => 60];

function poa_client_ip(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    if (filter_var($ip, FILTER_VALIDATE_IP)) {
        return $ip;
    }
    return '0.0.0.0';
}

function poa_rate_limit_dir(): string
{
    return poa_cache_dir() . '/ratelimit';
}

/**
 * @return array{allowed:bool, remaining:int, reset:int, count:int}
 */
function poa_rate_limit_check(string $bucket, string $ip, int $max, int $windowSec, ?int $now = null): array
{
    $max = max(1, $max);
    $windowSec = max(1, $windowSec);
    $now = $now ?? time();
    $dir = poa_rate_limit_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }
    $key = hash('sha256', $bucket . '|' . $ip);
    $file = $dir . '/' . $key . '.json';
    $fh = @fopen($file, 'c+');
    if ($fh === false) {
        // Fail open on storage errors — do not take the site down for RL I/O.
        return ['allowed' => true, 'remaining' => $max, 'reset' => $now + $windowSec, 'count' => 0];
    }
    flock($fh, LOCK_EX);
    try {
        $raw = stream_get_contents($fh);
        $state = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($state) || !isset($state['start'], $state['count'])) {
            $state = ['start' => $now, 'count' => 0];
        }
        $start = (int) $state['start'];
        $count = (int) $state['count'];
        if ($now - $start >= $windowSec) {
            $start = $now;
            $count = 0;
        }
        $count++;
        $allowed = $count <= $max;
        $payload = json_encode(['start' => $start, 'count' => $count], JSON_UNESCAPED_SLASHES);
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, (string) $payload);
        fflush($fh);
        return [
            'allowed' => $allowed,
            'remaining' => max(0, $max - $count),
            'reset' => $start + $windowSec,
            'count' => $count,
        ];
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

function poa_rate_limit_headers(array $result, int $max, int $windowSec): void
{
    header('X-RateLimit-Limit: ' . $max);
    header('X-RateLimit-Remaining: ' . (int) ($result['remaining'] ?? 0));
    header('X-RateLimit-Reset: ' . (int) ($result['reset'] ?? (time() + $windowSec)));
    if (empty($result['allowed'])) {
        $retry = max(1, (int) ($result['reset'] ?? time()) - time());
        header('Retry-After: ' . $retry);
    }
}

function poa_rate_limit_reject_html(int $retryAfter): void
{
    http_response_code(429);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>429 · PoA Explorer</title></head><body>';
    echo '<h1>Too many requests</h1>';
    echo '<p>This path is rate-limited so crawlers cannot pin the shared host. ';
    echo 'Humans looking up a specific tx or address at a normal pace are fine. ';
    echo 'Programmatic bulk access belongs on the JSON API (also rate-limited) or a local mirror.</p>';
    echo '<p>Retry after about ' . (int) $retryAfter . 's. See <a href="docs.php">docs</a> and <a href="robots.txt">robots.txt</a>.</p>';
    echo '</body></html>';
}

function poa_rate_limit_reject_json(int $retryAfter): void
{
    http_response_code(429);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    header('Access-Control-Allow-Origin: *');
    echo json_encode([
        'ok' => false,
        'error' => 'rate limited — retry shortly; bulk crawls of tx/address are discouraged',
        'cache' => 'miss',
        'version' => POA_EXPLORER_API_VERSION,
        'body' => ['retry_after_s' => $retryAfter],
    ], JSON_UNESCAPED_SLASHES);
}

/**
 * Enforce a bucket. Exits with 429 when over limit.
 *
 * @param array{max:int, window:int} $spec
 */
function poa_rate_limit_enforce(string $bucket, array $spec, bool $json = false): void
{
    $max = (int) ($spec['max'] ?? 12);
    $window = (int) ($spec['window'] ?? 60);
    $result = poa_rate_limit_check($bucket, poa_client_ip(), $max, $window);
    poa_rate_limit_headers($result, $max, $window);
    if (!empty($result['allowed'])) {
        return;
    }
    $retry = max(1, (int) ($result['reset'] ?? time()) - time());
    if ($json) {
        poa_rate_limit_reject_json($retry);
    } else {
        poa_rate_limit_reject_html($retry);
    }
    exit;
}

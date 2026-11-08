<?php
declare(strict_types=1);

/**
 * CLI only. Light health + market refresh. Never touches /api/epochs.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "CLI only\n";
    exit(1);
}

@ini_set('memory_limit', '128M');
@set_time_limit(120);

require_once dirname(__DIR__) . '/includes/poa.php';

$t0 = microtime(true);
// Network I/O unlocked; short lock only inside writers after fetch (poa_health_run).
$health = poa_health_run();
$market = poa_market_refresh();
$elapsed = microtime(true) - $t0;
$err = null;
if (!empty($health['_error'])) {
    $err = $health['_error'];
} elseif (!empty($market['_error'])) {
    $err = $market['_error'];
}
$line = sprintf(
    "%s light elapsed=%.1fs health=%s market=%s%s\n",
    gmdate('c'),
    $elapsed,
    (string) (($health['body']['overall'] ?? '') ?: ($health['_cache'] ?? '')),
    (string) (($market['body']['market_state'] ?? '') ?: ($market['_cache'] ?? '')),
    $err ? (' error=' . $err) : ''
);
fwrite(STDOUT, $line);
exit($err ? 1 : 0);

<?php
declare(strict_types=1);

/**
 * CLI only. Builds the slim epochs snapshot that address/pending/Reports read.
 * Do not fetch /api/epochs from a web request.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "CLI only\n";
    exit(1);
}

require_once dirname(__DIR__) . '/includes/poa.php';

$t0 = microtime(true);
$row = poa_refresh_derived();
$elapsed = microtime(true) - $t0;
$err = $row['_error'] ?? null;
$line = sprintf(
    "%s cache=%s status=%s elapsed=%.1fs%s\n",
    gmdate('c'),
    (string) ($row['_cache'] ?? ''),
    (string) ($row['_status'] ?? ''),
    $elapsed,
    $err ? (' error=' . $err) : ''
);
fwrite(STDOUT, $line);
exit($err ? 1 : 0);

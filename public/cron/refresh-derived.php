<?php
declare(strict_types=1);

/**
 * CLI only. Tip-gated heavy ingest: stream-strip epochs → SQLite + reports/last-close.
 * Do not fetch full /api/epochs inside php-fpm. Health/market: refresh-light.php.
 *
 * Flags: --force  --from-file=/path/to/epochs.json (bootstrap while origin paused)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "CLI only\n";
    exit(1);
}

@ini_set('memory_limit', '192M');
@set_time_limit(600);

require_once dirname(__DIR__) . '/includes/poa.php';

$force = in_array('--force', $argv ?? [], true);
$t0 = microtime(true);
$row = poa_refresh_derived($force);
$elapsed = microtime(true) - $t0;
$err = $row['_error'] ?? null;
$line = sprintf(
    "%s cache=%s status=%s elapsed=%.1fs tip=%s closes=%s%s\n",
    gmdate('c'),
    (string) ($row['_cache'] ?? ''),
    (string) ($row['_status'] ?? ''),
    $elapsed,
    isset($row['tip_epoch']) ? (string) $row['tip_epoch'] : '',
    isset($row['closes']) ? (string) $row['closes'] : '',
    $err ? (' error=' . $err) : (isset($row['_note']) ? (' note=' . $row['_note']) : '')
);
fwrite(STDOUT, $line);
exit($err ? 1 : 0);

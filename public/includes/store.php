<?php
declare(strict_types=1);

/**
 * Slim epochs store (SQLite). Built by CLI stripper; FPM only reads.
 */

function poa_store_path(): string
{
    return poa_cache_dir() . '/epochs-slim.sqlite';
}

function poa_store_jsonl_path(): string
{
    return poa_cache_dir() . '/epochs-slim.jsonl';
}

function poa_store_pdo(): ?PDO
{
    $path = poa_store_path();
    if (!is_file($path)) {
        return null;
    }
    static $pdo = null;
    static $opened = '';
    if ($pdo instanceof PDO && $opened === $path) {
        return $pdo;
    }
    try {
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $opened = $path;
        return $pdo;
    } catch (Throwable $e) {
        return null;
    }
}

function poa_store_present(): bool
{
    $p = poa_store_path();
    return is_file($p) && filesize($p) > 0;
}

function poa_store_mtime(): ?int
{
    $p = poa_store_path();
    if (!is_file($p)) {
        return null;
    }
    $t = filemtime($p);
    return $t === false ? null : $t;
}

/** @return list<array<string,mixed>> */
function poa_store_load_slim_closes(): array
{
    // Prefer SQLite (ordered by epoch). JSONL is optional legacy.
    $pdo = poa_store_pdo();
    if ($pdo instanceof PDO) {
        $out = [];
        foreach ($pdo->query('SELECT json FROM closes ORDER BY epoch ASC') as $row) {
            $decoded = json_decode((string) ($row['json'] ?? ''), true);
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }
        return $out;
    }
    $path = poa_store_jsonl_path();
    if (!is_file($path)) {
        return [];
    }
    $out = [];
    $fh = fopen($path, 'r');
    if ($fh === false) {
        return [];
    }
    try {
        while (($line = fgets($fh)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $row = json_decode($line, true);
            if (is_array($row)) {
                $out[] = $row;
            }
        }
    } finally {
        fclose($fh);
    }
    return $out;
}

function poa_store_close_by_epoch(int $epoch): ?array
{
    $pdo = poa_store_pdo();
    if ($pdo === null) {
        return null;
    }
    $st = $pdo->prepare('SELECT json FROM closes WHERE epoch = ?');
    $st->execute([$epoch]);
    $row = $st->fetch();
    if (!$row || empty($row['json'])) {
        return null;
    }
    $decoded = json_decode((string) $row['json'], true);
    return is_array($decoded) ? $decoded : null;
}

function poa_store_latest_close(): ?array
{
    $pdo = poa_store_pdo();
    if ($pdo === null) {
        return null;
    }
    $row = $pdo->query('SELECT json FROM closes ORDER BY epoch DESC LIMIT 1')->fetch();
    if (!$row || empty($row['json'])) {
        return null;
    }
    $decoded = json_decode((string) $row['json'], true);
    return is_array($decoded) ? $decoded : null;
}

/** Catalog of {epoch, ts} newest first. */
function poa_store_catalog(int $limit = 500): array
{
    $pdo = poa_store_pdo();
    if ($pdo === null) {
        return [];
    }
    $limit = max(1, min(5000, $limit));
    $st = $pdo->query('SELECT epoch, ts FROM closes ORDER BY epoch DESC LIMIT ' . $limit);
    $out = [];
    foreach ($st as $row) {
        $out[] = [
            'epoch' => isset($row['epoch']) ? (int) $row['epoch'] : null,
            'ts' => isset($row['ts']) ? (int) $row['ts'] : null,
        ];
    }
    return $out;
}

/** Last N slim closes (newest last, matching historic list order). */
function poa_store_window(int $n = 30): array
{
    $pdo = poa_store_pdo();
    if ($pdo === null) {
        return [];
    }
    $n = max(1, min(500, $n));
    $st = $pdo->query(
        'SELECT json FROM closes ORDER BY epoch DESC LIMIT ' . $n
    );
    $rows = [];
    foreach ($st as $row) {
        $decoded = json_decode((string) ($row['json'] ?? ''), true);
        if (is_array($decoded)) {
            $rows[] = $decoded;
        }
    }
    return array_reverse($rows);
}

/**
 * Settled hits for answer txids. Keyed lookup — never loads the whole index.
 *
 * @param array<string,mixed> $txIds
 * @return list<array<string,mixed>>
 */
function poa_store_settled_for_answers(array $txIds): array
{
    $pdo = poa_store_pdo();
    if ($pdo === null || $txIds === []) {
        return [];
    }
    $ids = [];
    foreach ($txIds as $aid => $_) {
        $aid = strtolower((string) $aid);
        if ($aid !== '') {
            $ids[] = $aid;
        }
    }
    $ids = array_values(array_unique($ids));
    if ($ids === []) {
        return [];
    }
    $hits = [];
    // Chunk IN clauses
    foreach (array_chunk($ids, 200) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $st = $pdo->prepare(
            "SELECT answer_txid, close_epoch, close_ts, \"from\", passed FROM settled WHERE answer_txid IN ($placeholders)"
        );
        $st->execute($chunk);
        foreach ($st as $row) {
            $hits[] = [
                'answer_txid' => $row['answer_txid'],
                'close_epoch' => isset($row['close_epoch']) ? (int) $row['close_epoch'] : null,
                'close_ts' => isset($row['close_ts']) ? (int) $row['close_ts'] : null,
                'from' => $row['from'] ?? null,
                'passed' => isset($row['passed']) ? ((int) $row['passed'] === 1) : null,
            ];
        }
    }
    return $hits;
}

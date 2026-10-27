<?php
declare(strict_types=1);

/**
 * Read-only PoA explorer. Public chain JSON only.
 * Never call /api/hosted/*. Never handle keys or send POA.
 */

const POA_EXPLORER_ORIGIN = 'https://minepoa.com';
const POA_EXPLORER_API_VERSION = '0.5.0';
const POA_EXPLORER_SLOW_CACHE_TTL = 180;
const POA_EXPLORER_DERIVED_STALE_AFTER = 900;
const POA_EXPLORER_EPOCHS_MEMORY = '384M';
const POA_EXPLORER_PENDING_PAGE_LIMIT = 100;
const POA_EXPLORER_EPOCH_EXPIRY = 16;
const POA_REPORTS_WINDOW = 30;

/** Origin /api/epochs close-record keys (unchanged passthrough). */
const POA_CLOSE_RECORD_KEYS = [
    'epoch',
    'ts',
    'pool',
    'note',
    'basis',
    'weights',
    'challenges',
    'propagation',
    'rewards',
];

function poa_origin(): string
{
    $env = getenv('POA_ORIGIN');
    if (is_string($env) && $env !== '') {
        return rtrim($env, '/');
    }
    return POA_EXPLORER_ORIGIN;
}
const POA_EXPLORER_UA = 'PoA-Explorer/0.5.0 (read-only; https://poa.explorer.zero1.network)';
const POA_EXPLORER_TIMEOUT = 20;
const POA_EXPLORER_CACHE_TTL = 45;
const POA_EXPLORER_RECENT_TX_LIMIT = 40;
const POA_EXPLORER_ANCHOR_LIMIT = 40;

function poa_cache_dir(): string
{
    $env = getenv('POA_CACHE_DIR');
    if (is_string($env) && $env !== '') {
        return $env;
    }
    return sys_get_temp_dir() . '/poa-explorer-cache';
}

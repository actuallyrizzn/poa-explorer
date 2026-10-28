<?php
declare(strict_types=1);

/**
 * Read-only PoA explorer. Public chain JSON only.
 * Never call /api/hosted/*. Never handle keys or send POA.
 */

const POA_EXPLORER_ORIGIN = 'https://minepoa.com';
const POA_EXPLORER_API_VERSION = '0.6.0';
const POA_EXPLORER_SLOW_CACHE_TTL = 180;
const POA_EXPLORER_DERIVED_STALE_AFTER = 900;
const POA_HEALTH_TTL = 45;
const POA_MARKET_TTL = 60;
const POA_USD_TTL = 300;
const POA_MARKET_HISTORY_CAP = 2880;
const POA_MARKET_HISTORY_MIN_GAP = 240;
const POA_SWAP_BOOK_URL = 'https://poaswap.io/api/book';
const POA_COINGECKO_SIMPLE_URL = 'https://api.coingecko.com/api/v3/simple/price?ids=solana,ethereum&vs_currencies=usd';
const POA_SETTLE_DEGRADED_AFTER = 28800;
const POA_SETTLE_DOWN_AFTER = 57600;
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

function poa_swap_book_url(): string
{
    $env = getenv('POA_SWAP_BOOK_URL');
    if (is_string($env) && $env !== '') {
        return $env;
    }
    return POA_SWAP_BOOK_URL;
}

function poa_coingecko_url(): string
{
    $env = getenv('POA_COINGECKO_SIMPLE_URL');
    if (is_string($env) && $env !== '') {
        return $env;
    }
    return POA_COINGECKO_SIMPLE_URL;
}

function poa_url_is_allowed(string $url): bool
{
    if (preg_match('#^https://#i', $url)) {
        return true;
    }
    return (bool) preg_match('#^http://(127\.0\.0\.1|\[::1\]|localhost)(:\d+)?/#i', $url);
}
const POA_EXPLORER_UA = 'PoA-Explorer/0.6.0 (read-only; https://poa.explorer.zero1.network)';
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

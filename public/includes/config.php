<?php
declare(strict_types=1);

/**
 * Read-only PoA explorer. Public chain JSON only.
 * Never call /api/hosted/*. Never handle keys or send POA.
 */

const POA_EXPLORER_ORIGIN = 'https://minepoa.com';

function poa_origin(): string
{
    $env = getenv('POA_ORIGIN');
    if (is_string($env) && $env !== '') {
        return rtrim($env, '/');
    }
    return POA_EXPLORER_ORIGIN;
}
const POA_EXPLORER_UA = 'PoA-Explorer/0.2 (read-only; https://poa.explorer.zero1.network)';
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

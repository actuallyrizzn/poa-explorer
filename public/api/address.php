<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/poa.php';

poa_rate_limit_enforce('api-address', POA_RL_ADDRESS_API, true);

$addr = trim((string) ($_GET['a'] ?? $_GET['address'] ?? ''));
poa_json_out(poa_address($addr));

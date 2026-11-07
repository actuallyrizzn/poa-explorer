<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/poa.php';

poa_rate_limit_enforce('api-tx', POA_RL_TX_API, true);

$id = trim((string) ($_GET['id'] ?? ''));
poa_json_out(poa_tx($id));

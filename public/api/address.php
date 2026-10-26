<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/poa.php';

$addr = trim((string) ($_GET['a'] ?? $_GET['address'] ?? ''));
poa_json_out(poa_address($addr));

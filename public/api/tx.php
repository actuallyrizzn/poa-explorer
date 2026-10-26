<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/poa.php';

$id = trim((string) ($_GET['id'] ?? ''));
poa_json_out(poa_tx($id));

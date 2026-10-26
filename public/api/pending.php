<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/poa.php';

$addr = trim((string) ($_GET['a'] ?? $_GET['address'] ?? ''));
$without = isset($_GET['without_panel']) && $_GET['without_panel'] !== '0' && $_GET['without_panel'] !== '';
$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : POA_EXPLORER_PENDING_PAGE_LIMIT;
poa_json_out(poa_pending_row($addr, $without, $limit));

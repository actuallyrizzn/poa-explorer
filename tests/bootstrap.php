<?php
declare(strict_types=1);

putenv('POA_CACHE_DIR=' . sys_get_temp_dir() . '/poa-explorer-cache-test');
require_once dirname(__DIR__) . '/public/includes/poa.php';

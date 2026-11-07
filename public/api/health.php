<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/poa.php';

poa_rate_limit_enforce('api-health', POA_RL_HEALTH, true);

poa_json_out(poa_health_row());

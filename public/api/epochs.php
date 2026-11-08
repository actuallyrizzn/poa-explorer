<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/poa.php';

// Windowed slim closes by default. ?mode=catalog → {epoch,ts} list only.
// Full minepoa /api/epochs mirror removed from FPM (commented in poa_json_get).
$mode = strtolower((string) ($_GET['mode'] ?? 'window'));
if ($mode === 'catalog') {
    poa_json_out(poa_epochs_catalog());
} else {
    poa_json_out(poa_epochs());
}

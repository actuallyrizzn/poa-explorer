<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/public/includes/poa.php';

$fail = 0;
function expect(bool $ok, string $msg): void
{
    global $fail;
    if (!$ok) {
        fwrite(STDERR, "FAIL $msg\n");
        $fail++;
    } else {
        echo "ok $msg\n";
    }
}

expect(poa_is_tx_id('54187e2ad9d4bf5444b6bc705598f343fbf26b4f1c0dda2a7fd943fec537d944'), 'tx id');
expect(!poa_is_tx_id('nope'), 'reject short tx');
expect(poa_is_address('poa1AoECFMm8ge4C9dx8x7LzBxvaByGsZagiT6HY1GYwBa5W'), 'address');
expect(!poa_is_address('0xabc'), 'reject evm');
expect(poa_is_height('38860'), 'height');
expect(!poa_is_height('38-860'), 'reject junk height');

$hosted = poa_json_get('/api/hosted/me');
expect($hosted['_error'] !== null, 'hosted blocked');

if ($fail) {
    exit(1);
}
echo "all ok\n";

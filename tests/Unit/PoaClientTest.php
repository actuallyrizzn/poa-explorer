<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

final class PoaClientTest extends TestCase
{
    public function testTxId(): void
    {
        $this->assertTrue(poa_is_tx_id('54187e2ad9d4bf5444b6bc705598f343fbf26b4f1c0dda2a7fd943fec537d944'));
        $this->assertFalse(poa_is_tx_id('nope'));
        $this->assertFalse(poa_is_tx_id(str_repeat('g', 64)));
    }

    public function testAddress(): void
    {
        $this->assertTrue(poa_is_address('poa1AoECFMm8ge4C9dx8x7LzBxvaByGsZagiT6HY1GYwBa5W'));
        $this->assertFalse(poa_is_address('0xabc'));
        $this->assertFalse(poa_is_address('poa1short'));
    }

    public function testHeight(): void
    {
        $this->assertTrue(poa_is_height('38860'));
        $this->assertFalse(poa_is_height('38-860'));
        $this->assertFalse(poa_is_height(''));
    }

    public function testFormatters(): void
    {
        $this->assertSame('—', poa_format_ts(null));
        $this->assertSame('—', poa_format_ts(0));
        $this->assertStringContainsString('UTC', poa_format_ts(1792977289));
        $this->assertSame('—', poa_format_amount(null));
        $this->assertSame('—', poa_format_amount(''));
        $this->assertSame('12.5', poa_format_amount('12.5'));
        $this->assertSame('ab', poa_h('ab'));
        $this->assertSame('&lt;x&gt;', poa_h('<x>'));
        $this->assertSame('abcdefgh', poa_short_id('abcdefgh', 8));
        $this->assertSame('12345678…89abcdef', poa_short_id('1234567890abcdef89abcdef', 8));
    }

    public function testWrapAndHostedBlock(): void
    {
        $w = poa_wrap(['_error' => 'x']);
        $this->assertSame('x', $w['_error']);
        $this->assertArrayHasKey('body', $w);
        $hosted = poa_json_get('/api/hosted/me');
        $this->assertNotNull($hosted['_error']);
        $this->assertStringContainsString('hosted', (string) $hosted['_error']);
    }

    public function testInvalidTxAndAddress(): void
    {
        $this->assertSame('not a transaction id', poa_tx('nope')['_error']);
        $this->assertSame('not a PoA address', poa_address('0x1')['_error']);
    }

    public function testFinalityAndTxList(): void
    {
        $this->assertSame('unfinal', poa_finality_label([]));
        $this->assertSame('final', poa_finality_label(['final' => 1]));
        $this->assertSame('final via checkpoint @ 9', poa_finality_label([
            'finality' => ['final' => true, 'via' => 'checkpoint', 'height' => 9],
        ]));
        $this->assertSame([], poa_tx_list_from_address([]));
        $this->assertCount(1, poa_tx_list_from_address(['txs' => [['id' => 'a']]]));
        $this->assertSame([], poa_tx_list_from_address(['txs' => 'nope']));
    }

    public function testCacheDir(): void
    {
        $this->assertStringContainsString('poa-explorer-cache', poa_cache_dir());
    }

    public function testJsonPayload(): void
    {
        $ok = poa_json_payload(['body' => ['a' => 1], '_cache' => 'fresh']);
        $this->assertTrue($ok['ok']);
        $this->assertNull($ok['error']);
        $this->assertSame(POA_EXPLORER_API_VERSION, $ok['version']);
        $this->assertSame(['a' => 1], $ok['body']);
        $bad = poa_json_payload(['_error' => 'nope', 'body' => null, '_cache' => 'miss']);
        $this->assertFalse($bad['ok']);
        $this->assertSame('nope', $bad['error']);
        $emptyErr = poa_json_payload(['_error' => '', 'body' => []]);
        $this->assertTrue($emptyErr['ok']);
    }
}

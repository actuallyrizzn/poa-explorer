<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PoaLiveTest extends TestCase
{
    protected function setUp(): void
    {
        foreach (['reports', 'last-close', 'settled-index'] as $name) {
            $f = poa_derived_file($name);
            if (is_file($f)) {
                @unlink($f);
            }
        }
    }

    public function testStatus(): void
    {
        $row = poa_status();
        $this->assertEmpty($row['_error']);
        $this->assertIsArray($row['body']);
        $this->assertTrue(
            isset($row['body']['checkpoint_height']) || isset($row['body']['checkpoint']['height'])
        );
        $again = poa_json_get('/api/status');
        $this->assertContains($again['_cache'], ['hit', 'fresh']);
    }

    public function testRecentTxsAndAnchors(): void
    {
        $txs = poa_recent_txs();
        $this->assertNotEmpty($txs);
        $this->assertArrayHasKey('id', $txs[0]);
        $anchorRow = poa_json_get('/api/anchors');
        if (!empty($anchorRow['_error']) || !is_array($anchorRow['body'])) {
            $this->markTestSkipped('anchors unavailable: ' . (string) ($anchorRow['_error'] ?? 'bad body'));
        }
        $anchors = poa_anchors();
        $this->assertNotEmpty($anchors);
        $this->assertArrayHasKey('height', $anchors[0]);
    }

    public function testAddressAndTx(): void
    {
        $addr = poa_address('poa1AoECFMm8ge4C9dx8x7LzBxvaByGsZagiT6HY1GYwBa5W');
        $this->assertEmpty($addr['_error']);
        $this->assertSame(
            'poa1AoECFMm8ge4C9dx8x7LzBxvaByGsZagiT6HY1GYwBa5W',
            $addr['body']['address']
        );
        $this->assertNotEmpty($addr['body']['txs']);
        $id = (string) $addr['body']['txs'][0]['id'];
        $tx = poa_tx($id);
        $this->assertEmpty($tx['_error']);
        $this->assertSame($id, $tx['body']['id']);
    }

    public function testHttpErrorPath(): void
    {
        $row = poa_json_get('/api/explorer/tx/not-a-route-zzzz');
        $this->assertNotNull($row['_error']);
    }

    public function testEpochs(): void
    {
        $row = poa_epochs();
        $this->assertEmpty($row['_error']);
        $this->assertIsArray($row['body']);
        $this->assertNotEmpty($row['body']);
        $this->assertArrayHasKey('epoch', $row['body'][0]);
    }

    public function testReportsEpoch8CopperwickCase(): void
    {
        $row = poa_reports_row();
        $this->assertIsArray($row['body']);
        $closes = $row['body']['settlement']['rows'] ?? [];
        $e8 = null;
        foreach ((array) $closes as $c) {
            if (is_array($c) && (int) ($c['epoch'] ?? -1) === 8) {
                $e8 = $c;
                break;
            }
        }
        if ($e8 === null) {
            $this->markTestSkipped('epoch 8 close not in public epochs');
        }
        $this->assertSame(345, $e8['settled']);
        $birth3 = 0;
        foreach ((array) ($e8['cohorts'] ?? []) as $co) {
            if (is_array($co) && (int) ($co['birth_epoch'] ?? -1) === 3) {
                $birth3 = (int) ($co['settled'] ?? 0);
            }
        }
        $this->assertSame(0, $birth3);
    }
}

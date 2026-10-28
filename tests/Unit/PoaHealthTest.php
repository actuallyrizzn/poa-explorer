<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PoaHealthTest extends TestCase
{
    public function testRollup(): void
    {
        $ok = [poa_health_feed('a', 'A', 'ok'), poa_health_feed('b', 'B', 'ok')];
        $this->assertSame('OK', poa_health_rollup($ok));
        $part = [poa_health_feed('a', 'A', 'ok'), poa_health_feed('b', 'B', 'down')];
        $this->assertSame('Partial', poa_health_rollup($part));
        $deg = [poa_health_feed('a', 'A', 'ok'), poa_health_feed('b', 'B', 'degraded')];
        $this->assertSame('Partial', poa_health_rollup($deg));
        $down = [poa_health_feed('a', 'A', 'down'), poa_health_feed('b', 'B', 'down')];
        $this->assertSame('Down', poa_health_rollup($down));
        $this->assertSame('Down', poa_health_rollup([]));
    }

    public function testSincePersistsWhileDown(): void
    {
        $now = 1_800_000_000;
        $feeds = [poa_health_feed('tx_lookup', 'Tx lookup', 'down', 'timeout')];
        $first = poa_health_apply_since($feeds, [], $now);
        $this->assertSame($now, $first[0]['since']);
        $later = poa_health_apply_since($feeds, poa_health_since_map($first), $now + 90);
        $this->assertSame($now, $later[0]['since']);
        $ok = poa_health_apply_since(
            [poa_health_feed('tx_lookup', 'Tx lookup', 'ok')],
            poa_health_since_map($later),
            $now + 120
        );
        $this->assertNull($ok[0]['since']);
    }

    public function testSwapWritesFromBook(): void
    {
        $ok = poa_swap_writes_from_book(['state' => 'available', 'swaps_live' => true, 'writes' => null], null);
        $this->assertSame('ok', $ok['status']);
        $this->assertSame('open', $ok['market_state']);
        $paused = poa_swap_writes_from_book(['state' => 'paused', 'swaps_live' => false], null);
        $this->assertSame('down', $paused['status']);
        $this->assertSame('paused', $paused['market_state']);
        $writes = poa_swap_writes_from_book(['state' => 'available', 'writes' => 'down'], null);
        $this->assertSame('down', $writes['status']);
        $unread = poa_swap_writes_from_book(null, 'timeout');
        $this->assertSame('down', $unread['status']);
    }

    public function testBuildPartialWhenTxDown(): void
    {
        $now = 1_793_000_000;
        $body = poa_health_build([
            'status' => ['_error' => null, '_status' => 200, 'body' => ['supply' => '1', 'checkpoint_height' => 9]],
            'txs' => ['_error' => 'timeout', '_status' => 0, 'body' => null],
            'anchors' => ['_error' => null, '_status' => 200, 'body' => []],
            'swaps' => ['_error' => null, '_status' => 200, 'body' => []],
            'book' => ['state' => 'available', 'swaps_live' => true],
            'book_error' => null,
            'derived_present' => true,
            'derived_age' => 30,
            'last_close' => [
                'epoch' => 14,
                'ts' => $now - 100,
                'basis' => ['pending' => ['aa' => ['grades' => [['score' => 3, 'ts' => $now - 50]]]]],
            ],
        ], [], $now);
        $this->assertSame('Partial', $body['overall']);
        $ids = [];
        foreach ($body['feeds'] as $f) {
            $ids[$f['id']] = $f['status'];
        }
        $this->assertSame('ok', $ids['status']);
        $this->assertSame('down', $ids['tx_lookup']);
        $this->assertSame('ok', $ids['swap_writes']);
    }

    public function testStaleIndexAndNoSeals(): void
    {
        $now = 1_793_000_000;
        $body = poa_health_build([
            'status' => ['_error' => null, '_status' => 200, 'body' => ['supply' => '1']],
            'txs' => ['_error' => null, '_status' => 200, 'body' => [['id' => 'a']]],
            'anchors' => ['_error' => null, '_status' => 200, 'body' => []],
            'swaps' => ['_error' => null, '_status' => 200, 'body' => []],
            'book' => ['state' => 'available'],
            'derived_present' => true,
            'derived_age' => 2000,
            'last_close' => ['epoch' => 1, 'ts' => $now - 100, 'basis' => ['pending' => ['aa' => ['grades' => []]]]],
        ], [], $now);
        $by = [];
        foreach ($body['feeds'] as $f) {
            $by[$f['id']] = $f;
        }
        $this->assertSame('degraded', $by['epochs_basis']['status']);
        $this->assertStringContainsString('behind', (string) $by['epochs_basis']['note']);
        $this->assertSame('degraded', $by['seals']['status']);
        $this->assertSame('Partial', $body['overall']);
    }

    public function testBannerHtml(): void
    {
        $html = poa_health_banner_html([
            'overall' => 'Partial',
            'feeds' => [
                poa_health_feed('tx_lookup', 'Tx lookup', 'down', 'x', 0, 1793000000),
            ],
        ]);
        $this->assertStringContainsString('Network Partial', $html);
        $this->assertStringContainsString('health.php', $html);
        $this->assertStringContainsString('health-partial', $html);
        $this->assertStringContainsString('Tx lookup down', $html);
    }

    public function testUrlJsonRefusesHttp(): void
    {
        $row = poa_url_json('http://example.com/x', 10);
        $this->assertNotNull($row['_error']);
        $this->assertStringContainsString('https', (string) $row['_error']);
        $this->assertFalse(poa_url_is_allowed('http://example.com/x'));
        $this->assertTrue(poa_url_is_allowed('https://poaswap.io/api/book'));
        $this->assertTrue(poa_url_is_allowed('http://127.0.0.1:9/api/book'));
    }

    public function testSwapWritesStaleBook(): void
    {
        $d = poa_swap_writes_from_book(['state' => 'available', 'book_state' => 'stale'], null);
        $this->assertSame('degraded', $d['status']);
        $this->assertSame('open', $d['market_state']);
    }

    public function testSettlementAgeBands(): void
    {
        $now = 2_000_000_000;
        $ok = poa_health_settlement_from_close(['epoch' => 1, 'ts' => $now - 10], $now);
        $this->assertSame('ok', $ok['status']);
        $deg = poa_health_settlement_from_close(['epoch' => 1, 'ts' => $now - POA_SETTLE_DEGRADED_AFTER - 1], $now);
        $this->assertSame('degraded', $deg['status']);
        $down = poa_health_settlement_from_close(['epoch' => 1, 'ts' => $now - POA_SETTLE_DOWN_AFTER - 1], $now);
        $this->assertSame('down', $down['status']);
        $missing = poa_health_settlement_from_close(null, $now);
        $this->assertSame('degraded', $missing['status']);
    }

    public function testEmptyTxListIsDegraded(): void
    {
        $row = poa_health_origin_row(
            ['_error' => null, '_status' => 200, 'body' => []],
            'tx_lookup',
            'Tx lookup',
            static function ($body) {
                if (!is_array($body)) {
                    return 'txs body missing';
                }
                if ($body === []) {
                    return ['status' => 'degraded', 'note' => 'tx list empty'];
                }
                return true;
            }
        );
        $this->assertSame('degraded', $row['status']);
    }

    public function testBannerOkAndEmpty(): void
    {
        $ok = poa_health_banner_html(['overall' => 'OK', 'feeds' => [poa_health_feed('status', 'Status', 'ok')]]);
        $this->assertStringContainsString('Network OK', $ok);
        $this->assertStringContainsString('All probed feeds ok', $ok);
        $down = poa_health_banner_html(['overall' => 'Down', 'feeds' => [poa_health_feed('status', 'Status', 'down')]]);
        $this->assertStringContainsString('health-down', $down);
    }

    public function testEpochsBasisMissing(): void
    {
        $row = poa_health_epochs_basis(false, null);
        $this->assertSame('degraded', $row['status']);
    }
}

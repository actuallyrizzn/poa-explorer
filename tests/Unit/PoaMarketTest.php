<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PoaMarketTest extends TestCase
{
    public function testUsdImplied(): void
    {
        $rates = ['SOL' => 100.0, 'ETH' => 2000.0, 'USDC' => 1.0];
        $this->assertEqualsWithDelta(0.00004, poa_usd_implied(0.4, 'SOL', $rates), 1e-12);
        $this->assertNull(poa_usd_implied(null, 'SOL', $rates));
        $this->assertNull(poa_usd_implied(0.4, 'DOGE', $rates));
    }

    public function testCoingeckoRates(): void
    {
        $r = poa_usd_rates_from_coingecko([
            'solana' => ['usd' => 101.91],
            'ethereum' => ['usd' => 2350],
        ]);
        $this->assertSame(101.91, $r['SOL']);
        $this->assertSame(2350.0, $r['ETH']);
        $this->assertSame(1.0, $r['USDC']);
    }

    public function testPairLabelsAreOfferAndTrade(): void
    {
        $p = poa_market_pair_from_book_pair([
            'key' => 'SOL|solana',
            'asset' => 'SOL',
            'chain' => 'solana',
            'chain_name' => 'Solana',
            'best_ask' => 0.15,
            'last' => 0.4,
            'last_ts' => 1792791356,
            'asks' => [['px' => 0.15, 'poa' => 69420, 'want' => 0.010413]],
            'ask_only' => true,
            'last_label' => 'Ask only, no trades since Oct 23',
        ], ['SOL' => 101.91]);
        $this->assertSame('best offer', $p['best_offer']['label']);
        $this->assertSame('last trade', $p['last_trade']['label']);
        $this->assertSame(0.15, $p['best_offer']['per_1m_poa']);
        $this->assertSame(0.4, $p['last_trade']['per_1m_poa']);
        $this->assertEqualsWithDelta(0.000040764, $p['last_trade']['usd_per_poa'], 1e-9);
        $this->assertSame(1, $p['open_offers']);
    }

    public function testFromBookNeverCallsItLivePrice(): void
    {
        $book = [
            'state' => 'available',
            'pairs' => [[
                'key' => 'SOL|solana',
                'asset' => 'SOL',
                'chain' => 'solana',
                'best_ask' => 0.15,
                'last' => 0.4,
                'asks' => [['px' => 0.15, 'poa' => 1, 'want' => 0.00000015]],
            ]],
            'usd' => ['src' => 'CoinGecko', 'rates' => ['SOL' => 100]],
        ];
        $body = poa_market_from_book(
            $book,
            ['SOL' => 100.0, 'USDC' => 1.0],
            [],
            ['status' => 'ok', 'market_state' => 'open', 'note' => 'n'],
            1_793_000_000
        );
        $this->assertTrue($body['otc']);
        $this->assertStringContainsString('Not an AMM', $body['mechanism']);
        $this->assertStringContainsString('never a live market price', $body['disclaimer']);
        $this->assertSame('open', $body['market_state']);
        $this->assertSame('SOL|solana', $body['primary']);
        $this->assertSame(100, $body['fee_bp']);
        $this->assertStringContainsString('never a live market price', strtolower((string) json_encode($body)));
    }

    public function testHistoryGapAndCap(): void
    {
        $h = [];
        $h = poa_market_history_append($h, ['ts' => 100, 'pairs' => []], 100, 50, 3);
        $h = poa_market_history_append($h, ['ts' => 120, 'pairs' => []], 120, 50, 3);
        $this->assertCount(1, $h);
        $h = poa_market_history_append($h, ['ts' => 160, 'pairs' => []], 160, 50, 3);
        $h = poa_market_history_append($h, ['ts' => 220, 'pairs' => []], 220, 50, 3);
        $h = poa_market_history_append($h, ['ts' => 280, 'pairs' => []], 280, 50, 3);
        $this->assertCount(3, $h);
        $this->assertSame(160, $h[0]['ts']);
    }

    public function testHistorySeries(): void
    {
        $hist = [
            ['ts' => 1, 'pairs' => ['SOL|solana' => ['best_offer' => 0.2, 'last_trade' => 0.4]]],
            ['ts' => 2, 'pairs' => ['SOL|solana' => ['best_offer' => 0.15, 'last_trade' => 0.4]]],
        ];
        $offer = poa_market_history_series($hist, 'SOL|solana', 'best_offer');
        $trade = poa_market_history_series($hist, 'SOL|solana', 'last_trade');
        $this->assertSame(0.2, $offer[0]['value']);
        $this->assertSame(0.15, $offer[1]['value']);
        $this->assertSame(0.4, $trade[0]['value']);
        $this->assertSame(0.4, $trade[1]['value']);
    }

    public function testFmt(): void
    {
        $this->assertSame('—', poa_fmt_per_1m(null, 'SOL'));
        $this->assertSame('0.4 SOL / 1M POA', poa_fmt_per_1m(0.4, 'SOL'));
        $this->assertStringContainsString('implied', poa_fmt_usd_per_poa(0.00004));
        $this->assertSame('—', poa_fmt_usd_per_poa(null));
    }

    public function testBookUsdFallbackAndPrimary(): void
    {
        $r = poa_usd_rates_from_book(['usd' => ['rates' => ['SOL' => 99.5, 'ETH' => 1]]]);
        $this->assertSame(99.5, $r['SOL']);
        $this->assertNull(poa_market_primary(null));
        $this->assertNull(poa_market_primary(['primary' => 'SOL|solana', 'pairs' => []]));
        $p = poa_market_primary([
            'primary' => 'SOL|solana',
            'pairs' => [['key' => 'SOL|solana', 'asset' => 'SOL']],
        ]);
        $this->assertSame('SOL', $p['asset']);
        $this->assertNull(poa_num_or_null(''));
        $this->assertNull(poa_num_or_null('x'));
        $this->assertSame(1.5, poa_num_or_null('1.5'));
    }

    public function testMarketRowCacheHit(): void
    {
        $body = [
            'otc' => true,
            'as_of' => time(),
            'primary' => 'SOL|solana',
            'pairs' => [],
            'disclaimer' => 'never a live market price',
        ];
        if (!is_dir(poa_cache_dir())) {
            mkdir(poa_cache_dir(), 0770, true);
        }
        file_put_contents(poa_derived_file('market'), json_encode($body));
        $row = poa_market_row();
        $this->assertSame('hit', $row['_cache']);
        $this->assertTrue($row['body']['otc']);
    }
}

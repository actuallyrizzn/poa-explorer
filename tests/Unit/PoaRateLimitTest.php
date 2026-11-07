<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PoaRateLimitTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/poa-rl-test-' . bin2hex(random_bytes(4));
        putenv('POA_CACHE_DIR=' . $this->dir);
        mkdir($this->dir, 0770, true);
    }

    protected function tearDown(): void
    {
        $rl = $this->dir . '/ratelimit';
        if (is_dir($rl)) {
            foreach (scandir($rl) ?: [] as $f) {
                if ($f === '.' || $f === '..') {
                    continue;
                }
                @unlink($rl . '/' . $f);
            }
            @rmdir($rl);
        }
        @rmdir($this->dir);
        putenv('POA_CACHE_DIR');
    }

    public function testAllowsUnderCapThenBlocks(): void
    {
        $now = 1_700_000_000;
        for ($i = 1; $i <= 3; $i++) {
            $r = poa_rate_limit_check('t', '203.0.113.9', 3, 60, $now);
            $this->assertTrue($r['allowed'], "request $i should be allowed");
        }
        $blocked = poa_rate_limit_check('t', '203.0.113.9', 3, 60, $now);
        $this->assertFalse($blocked['allowed']);
        $this->assertSame(0, $blocked['remaining']);
    }

    public function testWindowResets(): void
    {
        $now = 1_700_000_000;
        poa_rate_limit_check('w', '198.51.100.2', 1, 60, $now);
        $blocked = poa_rate_limit_check('w', '198.51.100.2', 1, 60, $now);
        $this->assertFalse($blocked['allowed']);
        $ok = poa_rate_limit_check('w', '198.51.100.2', 1, 60, $now + 60);
        $this->assertTrue($ok['allowed']);
    }

    public function testBucketsAndIpsAreIndependent(): void
    {
        $now = 1_700_000_000;
        poa_rate_limit_check('a', '203.0.113.1', 1, 60, $now);
        $otherIp = poa_rate_limit_check('a', '203.0.113.2', 1, 60, $now);
        $otherBucket = poa_rate_limit_check('b', '203.0.113.1', 1, 60, $now);
        $this->assertTrue($otherIp['allowed']);
        $this->assertTrue($otherBucket['allowed']);
    }
}

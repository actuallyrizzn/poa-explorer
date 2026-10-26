<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PoaFixtureOriginTest extends TestCase
{
    private static $proc;
    private static int $port = 0;

    public static function setUpBeforeClass(): void
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($sock, false);
        fclose($sock);
        self::$port = (int) explode(':', $name)[1];
        $router = dirname(__DIR__) . '/fixtures/origin-router.php';
        self::$proc = proc_open(
            ['php', '-S', '127.0.0.1:' . self::$port, $router],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            dirname($router)
        );
        usleep(250000);
        putenv('POA_ORIGIN=http://127.0.0.1:' . self::$port);
        putenv('POA_CACHE_DIR=' . sys_get_temp_dir() . '/poa-explorer-cache-fixture');
    }

    public static function tearDownAfterClass(): void
    {
        putenv('POA_ORIGIN');
        putenv('POA_CACHE_DIR=' . sys_get_temp_dir() . '/poa-explorer-cache-test');
        if (is_resource(self::$proc)) {
            proc_terminate(self::$proc);
            proc_close(self::$proc);
        }
    }

    public function testStatusRecoversFromChainScoped(): void
    {
        $row = poa_status();
        $this->assertNull($row['_error']);
        $this->assertSame('poa-fixture', $row['body']['chain_id']);
        $this->assertSame(7, $row['body']['checkpoint']['height']);
    }

    public function testValidBucketTxsAndEmptyAnchors(): void
    {
        $this->assertSame([['id' => 'abc', 'type' => 'x']], poa_recent_txs());
        $this->assertSame([], poa_anchors());
        $this->assertSame([['id' => 'abc', 'type' => 'x']], poa_recent_txs_row()['body']);
        $this->assertSame([], poa_anchors_row()['body']);
    }

    public function testEpochsAndChallenges(): void
    {
        $epochs = poa_epochs();
        $this->assertNull($epochs['_error']);
        $this->assertSame(0, $epochs['body'][0]['epoch']);
        $this->assertArrayHasKey('ts', $epochs['body'][0]);
        $this->assertArrayHasKey('basis', $epochs['body'][0]);
        $ch = poa_challenges();
        $this->assertNull($ch['_error']);
        $this->assertSame('poa-fixture', $ch['body']['chain_id']);
        $pend = poa_pending_row();
        $this->assertSame(1, $pend['body']['total']);
        $this->assertSame(1, $pend['body']['without_panel']);
        $close = poa_close_by_epoch('0');
        $this->assertSame(0, $close['body']['epoch']);
        $this->assertSame([], poa_close_keys_present($close['body']));
    }

    public function testErrorBodies(): void
    {
        $empty = poa_json_get('/empty');
        $this->assertNotNull($empty['_error']);
        $bad = poa_json_get('/badjson');
        $this->assertSame('origin returned non-JSON', $bad['_error']);
        $http = poa_json_get('/http400');
        $this->assertStringContainsString('origin HTTP 400', (string) $http['_error']);
    }

    public function testStaleCache(): void
    {
        $path = '/chain/poa-mainnet-1/api/status';
        $cache = poa_cache_dir() . '/' . hash('sha256', $path) . '.json';
        if (!is_dir(poa_cache_dir())) {
            mkdir(poa_cache_dir(), 0770, true);
        }
        file_put_contents($cache, json_encode(poa_wrap(['body' => ['ok' => 1], '_path' => $path])));
        touch($cache, time() - 120);
        $old = getenv('POA_ORIGIN');
        putenv('POA_ORIGIN=http://127.0.0.1:1');
        $row = poa_json_get($path);
        putenv('POA_ORIGIN=' . $old);
        $this->assertSame('stale', $row['_cache']);
        $this->assertSame(['ok' => 1], $row['body']);
    }

    public function testDefaultCacheDirWhenUnset(): void
    {
        $old = getenv('POA_CACHE_DIR');
        putenv('POA_CACHE_DIR');
        $dir = poa_cache_dir();
        putenv('POA_CACHE_DIR=' . $old);
        $this->assertStringEndsWith('/poa-explorer-cache', $dir);
    }
}

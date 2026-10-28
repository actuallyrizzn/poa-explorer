<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/sdk/php/PoaExplorerClient.php';

final class PoaSdkTest extends TestCase
{
    private static $proc;
    private static int $port = 0;

    public static function setUpBeforeClass(): void
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($sock, false);
        fclose($sock);
        self::$port = (int) explode(':', $name)[1];
        $router = dirname(__DIR__) . '/fixtures/explorer-api-router.php';
        self::$proc = proc_open(
            ['php', '-S', '127.0.0.1:' . self::$port, $router],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );
        usleep(250000);
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$proc)) {
            proc_terminate(self::$proc);
            proc_close(self::$proc);
        }
    }

    private function client(): PoaExplorerClient
    {
        return new PoaExplorerClient('http://127.0.0.1:' . self::$port);
    }

    public function testMethods(): void
    {
        $c = $this->client();
        $this->assertTrue($c->status()['ok']);
        $this->assertSame(9, $c->status()['body']['checkpoint']['height']);
        $this->assertSame('challenge', $c->recentTxs()['body'][0]['type']);
        $this->assertSame('abc', $c->tx('abc')['body']['id']);
        $this->assertSame('poa1x', $c->address('poa1x')['body']['address']);
        $this->assertSame(9, $c->anchors()['body'][0]['height']);
        $this->assertSame(0, $c->epochs()['body'][0]['epoch']);
        $this->assertSame([], $c->challenges()['body']['answers']);
        $this->assertSame('0.6.0', $c->changelog()['body']['current']);
        $this->assertArrayHasKey('settlement', $c->reports()['body']);
        $this->assertSame('OK', $c->health()['body']['overall']);
        $this->assertTrue($c->market()['body']['otc']);
        $this->assertSame(2, $c->close('2')['body']['epoch']);
        $this->assertSame(0, $c->pending()['body']['total']);
        $this->assertArrayHasKey('version', $c->status());
    }

    public function testTxErrorEnvelope(): void
    {
        $row = $this->client()->tx('bad');
        $this->assertFalse($row['ok']);
        $this->assertStringContainsString('transaction', (string) $row['error']);
    }

    public function testUnreachable(): void
    {
        $this->expectException(RuntimeException::class);
        (new PoaExplorerClient('http://127.0.0.1:1', 1))->status();
    }
}

<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PoaStoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/poa-store-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0770, true);
        putenv('POA_CACHE_DIR=' . $this->dir);
    }

    protected function tearDown(): void
    {
        putenv('POA_CACHE_DIR');
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function testStripperBuildsSqliteAndWindow(): void
    {
        $raw = [
            [
                'epoch' => 0,
                'ts' => 5,
                'pool' => '1',
                'note' => 'genesis',
                'basis' => ['pending' => [], 'settled' => []],
                'rewards' => [],
                'challenges' => [],
                'weights' => [],
            ],
            [
                'epoch' => 1,
                'ts' => 10,
                'pool' => '1',
                'note' => 'n',
                'challenges' => ['x'],
                'weights' => [1],
                'basis' => [
                    'pending' => ['aa' => ['kind' => 'short', 'panel' => [], 'since' => 1, 'grades' => []]],
                    'settled' => [['answer' => 'DeadBeef', 'from' => 'poa1a', 'passed' => true]],
                ],
                'rewards' => [],
            ],
            [
                'epoch' => 2,
                'ts' => 20,
                'pool' => '1',
                'note' => 'n2',
                'basis' => [
                    'pending' => [],
                    'settled' => [['answer' => 'aa', 'from' => 'poa1b', 'passed' => false]],
                ],
                'rewards' => ['poa1b' => '1'],
            ],
        ];
        $rawFile = $this->dir . '/raw.json';
        file_put_contents($rawFile, json_encode($raw));
        $bin = dirname(__DIR__, 2) . '/tools/strip_epochs_to_sqlite.py';
        $this->assertFileExists($bin);
        $cmd = 'python3 ' . escapeshellarg($bin)
            . ' ' . escapeshellarg($rawFile)
            . ' ' . escapeshellarg(poa_store_path())
            . ' --slim-jsonl ' . escapeshellarg(poa_store_jsonl_path())
            . ' 2>&1';
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertTrue(poa_store_present());

        $genesis = poa_store_close_by_epoch(0);
        $this->assertIsArray($genesis);
        $this->assertSame(0, (int) $genesis['epoch']);

        $close = poa_store_close_by_epoch(1);
        $this->assertIsArray($close);
        $this->assertSame(1, (int) $close['epoch']);
        $this->assertSame([], $close['challenges'] ?? null);

        $hits = poa_store_settled_for_answers(['deadbeef' => true]);
        $this->assertCount(1, $hits);
        $this->assertTrue($hits[0]['passed']);

        $window = poa_store_window(30);
        $this->assertCount(3, $window);
        $this->assertSame(0, (int) $window[0]['epoch']);
        $this->assertSame(1, (int) $window[1]['epoch']);
    }

    public function testEpochsApiUsesStoreNotOrigin(): void
    {
        $this->testStripperBuildsSqliteAndWindow();
        @file_put_contents(poa_derived_file('reports'), '{}');
        @file_put_contents(poa_derived_file('last-close'), json_encode(['epoch' => 2]));
        $row = poa_epochs();
        $this->assertNull($row['_error']);
        $this->assertIsArray($row['body']);
        $this->assertCount(3, $row['body']);
    }

    public function testHealthRowNeverRunsInline(): void
    {
        $row = poa_health_row();
        $this->assertSame('miss', $row['_cache']);
        $this->assertNotNull($row['_error']);
        @file_put_contents(poa_derived_file('health'), json_encode([
            'overall' => 'OK',
            'probed_at' => time(),
            'feeds' => [],
            'swap_writes' => [],
        ]));
        $hit = poa_health_row();
        $this->assertSame('hit', $hit['_cache']);
        $this->assertSame('OK', $hit['body']['overall']);
    }
}

<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PoaGradingTest extends TestCase
{
    private function closeFixture(): array
    {
        return [
            'epoch' => 7,
            'ts' => 1793030314,
            'pool' => '1',
            'note' => 'fixture',
            'basis' => [
                'pending' => [
                    'aa' => ['kind' => 'short', 'panel' => [], 'originalPassed' => false, 'since' => 2],
                    'bb' => ['kind' => 'short', 'panel' => ['poa1JuryJuryJuryJuryJuryJuryJuryJu1'], 'originalPassed' => false, 'since' => 1],
                ],
                'settled' => [
                    [
                        'answer' => 'cc',
                        'from' => 'poa1AuthorAuthorAuthorAuthorAuthorA1',
                        'passed' => true,
                        'losers' => [],
                        'winners' => [],
                        'jury' => [
                            ['grader' => 'poa1JuryJuryJuryJuryJuryJuryJuryJu1', 'aligned' => true],
                        ],
                    ],
                ],
                'graders_served' => [
                    ['poa1JuryJuryJuryJuryJuryJuryJuryJu1', 3],
                ],
            ],
            'weights' => [],
            'challenges' => [],
            'propagation' => [],
            'rewards' => ['poa1AuthorAuthorAuthorAuthorAuthorA1' => '1'],
        ];
    }

    private function challengesFixture(): array
    {
        return [
            'answers' => [
                'aa' => ['epoch' => 7, 'author' => 'poa1AuthorAuthorAuthorAuthorAuthorA1', 'source' => 'live'],
                'bb' => ['epoch' => 8, 'author' => 'poa1OtherOtherOtherOtherOtherOtherO1', 'source' => 'covered'],
            ],
        ];
    }

    public function testLatestCloseAndKeys(): void
    {
        $closes = [['epoch' => 1], $this->closeFixture(), ['epoch' => 3, 'ts' => 1]];
        $latest = poa_latest_close($closes);
        $this->assertSame(7, $latest['epoch']);
        $this->assertSame([], poa_close_keys_present($this->closeFixture()));
        $this->assertSame(['ts', 'pool', 'note', 'basis', 'weights', 'challenges', 'propagation', 'rewards'], poa_close_keys_present(['epoch' => 0]));
    }

    public function testPendingSnapshotPanelSplit(): void
    {
        $snap = poa_pending_snapshot($this->closeFixture(), $this->challengesFixture());
        $this->assertSame(7, $snap['as_of_epoch']);
        $this->assertSame(2, $snap['total']);
        $this->assertSame(1, $snap['with_panel']);
        $this->assertSame(1, $snap['without_panel']);
        $this->assertSame(1, $snap['settled_this_close']);
        $this->assertSame('poa1AuthorAuthorAuthorAuthorAuthorA1', $snap['rows'][0]['author']);
        $this->assertSame(0, $snap['rows'][0]['panel_size']);
        $this->assertNull($snap['rows'][0]['grades']);
        $this->assertNull($snap['rows'][0]['quorum']);
        $this->assertSame(2, $snap['rows'][0]['since']);
        $this->assertSame(1, $snap['rows'][1]['panel_size']);
    }

    public function testFilterPending(): void
    {
        $snap = poa_pending_snapshot($this->closeFixture(), $this->challengesFixture());
        $mine = poa_filter_pending_snapshot($snap, 'poa1AuthorAuthorAuthorAuthorAuthorA1', false, 10);
        $this->assertSame(1, $mine['matched']);
        $this->assertSame('aa', $mine['rows'][0]['answer']);
        $none = poa_filter_pending_snapshot($snap, '', true, 10);
        $this->assertSame(1, $none['matched']);
        $this->assertSame(0, $none['rows'][0]['panel_size']);
    }

    public function testGradingForAddress(): void
    {
        $addr = 'poa1AuthorAuthorAuthorAuthorAuthorA1';
        $g = poa_grading_for_address($addr, [
            'txs' => [
                ['id' => '11', 'type' => 'grade-sealed'],
                ['id' => '22', 'type' => 'challenge'],
            ],
        ], $this->closeFixture(), $this->challengesFixture());
        $this->assertSame(1, $g['pending_count']);
        $this->assertSame(1, $g['without_panel_count']);
        $this->assertSame(1, $g['answers_as_author_count']);
        $this->assertSame(1, $g['settled_as_author_this_close']);
        $this->assertCount(1, $g['grade_sealed_txs']);
        $this->assertTrue(poa_is_grade_tx(['type' => 'grade-sealed']));
        $this->assertFalse(poa_is_grade_tx(['type' => 'challenge']));

        $juryAddr = 'poa1JuryJuryJuryJuryJuryJuryJuryJu1';
        $j = poa_grading_for_address($juryAddr, ['txs' => []], $this->closeFixture(), $this->challengesFixture());
        $this->assertSame(0, $j['pending_count']);
        $this->assertSame(1, $j['jury_rows_this_close']);
        $this->assertSame(3, $j['graders_served_this_close']);
    }

    public function testChangelogAndCloseLookup(): void
    {
        $log = poa_changelog();
        $this->assertSame('0.3.0', $log[0]['version']);
        $row = poa_changelog_row();
        $this->assertSame(POA_EXPLORER_API_VERSION, $row['body']['current']);
        $bad = poa_close_by_epoch('nope');
        $this->assertNotNull($bad['_error']);
    }

    public function testEmptyPendingSnapshot(): void
    {
        $snap = poa_pending_snapshot(null, null);
        $this->assertSame(0, $snap['total']);
        $this->assertSame([], $snap['rows']);
    }
}

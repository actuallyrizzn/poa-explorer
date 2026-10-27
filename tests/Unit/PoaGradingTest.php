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
                    'bb' => ['kind' => 'short', 'panel' => ['poa1JuryJuryJuryJuryJuryJuryJuryJu1'], 'originalPassed' => false, 'since' => 1, 'grades' => []],
                    'dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd' => [
                        'kind' => 'short',
                        'panel' => [],
                        'originalPassed' => false,
                        'since' => 6,
                        'grades' => [
                            ['grader' => 'poa1JuryJuryJuryJuryJuryJuryJuryJu1', 'score' => 3, 'ts' => 1792956842],
                            ['score' => 2, 'ts' => 1792957382],
                        ],
                    ],
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
        $this->assertSame(3, $snap['total']);
        $this->assertSame(1, $snap['with_panel']);
        $this->assertSame(2, $snap['without_panel']);
        $this->assertSame(1, $snap['with_seals']);
        $this->assertSame(1, $snap['zero_seals']);
        $this->assertSame(1, $snap['missing_grades_field']);
        $this->assertSame(1, $snap['settled_this_close']);
        $this->assertSame(2, $snap['rows'][0]['grades_count']);
        $this->assertSame(3, $snap['rows'][0]['grades'][0]['score']);
        $this->assertSame(1792956842, $snap['rows'][0]['grades'][0]['ts']);
        $this->assertSame('dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd', $snap['rows'][0]['answer_txid']);
        $this->assertSame([], $snap['rows'][1]['grades']);
        $this->assertSame(0, $snap['rows'][1]['grades_count']);
        $this->assertSame(1, $snap['rows'][1]['panel_size']);
        $this->assertSame('poa1AuthorAuthorAuthorAuthorAuthorA1', $snap['rows'][2]['author']);
        $this->assertSame(0, $snap['rows'][2]['panel_size']);
        $this->assertNull($snap['rows'][2]['grades']);
        $this->assertNull($snap['rows'][2]['grades_count']);
        $this->assertStringContainsString('no grades field', (string) $snap['rows'][2]['grades_note']);
        $this->assertNull($snap['rows'][2]['quorum']);
        $this->assertSame(2, $snap['rows'][2]['since']);
    }

    public function testFilterPending(): void
    {
        $snap = poa_pending_snapshot($this->closeFixture(), $this->challengesFixture());
        $mine = poa_filter_pending_snapshot($snap, 'poa1AuthorAuthorAuthorAuthorAuthorA1', false, 10);
        $this->assertSame(1, $mine['matched']);
        $this->assertSame('aa', $mine['rows'][0]['answer']);
        $this->assertNull($mine['rows'][0]['grades']);
        $txid = 'dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd';
        $joined = poa_filter_pending_snapshot($snap, 'poa1AuthorAuthorAuthorAuthorAuthorA1', false, 10, [$txid]);
        $this->assertSame(2, $joined['matched']);
        $none = poa_filter_pending_snapshot($snap, '', true, 10);
        $this->assertSame(2, $none['matched']);
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
        $this->assertSame('0.4.1', $log[0]['version']);
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

    public function testAddressJoinsPendingAndSettledByAnswerTxid(): void
    {
        $addr = 'poa1AuthorAuthorAuthorAuthorAuthorA1';
        $txid = 'dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd';
        $settledTx = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';
        $older = [
            'epoch' => 3,
            'ts' => 1793000000,
            'basis' => [
                'pending' => [],
                'settled' => [
                    ['answer' => $settledTx, 'from' => 'poa1SomeoneElse', 'passed' => true, 'jury' => []],
                ],
            ],
        ];
        $g = poa_grading_for_address($addr, [
            'txs' => [
                ['id' => $txid, 'type' => 'challenge', 'epoch' => 7],
                ['id' => $settledTx, 'type' => 'challenge', 'epoch' => 3],
            ],
        ], $this->closeFixture(), $this->challengesFixture(), [$older, $this->closeFixture()]);
        $this->assertGreaterThanOrEqual(2, $g['pending_count']);
        $found = false;
        foreach ($g['pending'] as $row) {
            if (($row['answer_txid'] ?? '') === $txid) {
                $found = true;
                $this->assertSame(2, $row['grades_count']);
                $this->assertSame(0, $row['panel_size']);
            }
        }
        $this->assertTrue($found, 'pending row joined via challenge txid even without challenge-index author');
        $this->assertSame(1, $g['settled_by_answer_txid_count']);
        $this->assertSame($settledTx, $g['settled_by_answer_txid'][0]['answer_txid']);
        $this->assertSame(3, $g['settled_by_answer_txid'][0]['close_epoch']);
        $this->assertCount(2, $g['challenge_joins']);
        $this->assertNotNull($g['challenge_joins'][0]['pending']);
        $this->assertNotNull($g['challenge_joins'][1]['settled']);
    }
}

<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PoaReportsTest extends TestCase
{
    private function closes(): array
    {
        $e7 = [
            'epoch' => 7,
            'ts' => 100,
            'pool' => '1',
            'note' => 'e7',
            'basis' => [
                'pending' => [
                    'aa' => ['kind' => 'short', 'panel' => [], 'since' => 4, 'grades' => []],
                    'bb' => ['kind' => 'short', 'panel' => ['poa1JuryJuryJuryJuryJuryJuryJuryJu1'], 'since' => 2, 'grades' => [['score' => 3, 'ts' => 1]]],
                    'cc' => ['kind' => 'short', 'panel' => [], 'since' => 1, 'grades' => []],
                ],
                'settled' => [],
                'established_after' => [],
                'attestations' => ['poa1x' => 1],
            ],
            'weights' => [],
            'challenges' => [],
            'propagation' => [],
            'rewards' => [],
        ];
        $e8 = [
            'epoch' => 8,
            'ts' => 100 + 21600,
            'pool' => '1',
            'note' => 'e8',
            'basis' => [
                'pending' => [
                    'aa' => ['kind' => 'short', 'panel' => [], 'since' => 5, 'grades' => []],
                    'stay' => ['kind' => 'short', 'panel' => [], 'since' => 5, 'grades' => [['score' => 2, 'ts' => 2]]],
                ],
                'settled' => [
                    ['answer' => 'bb', 'from' => 'poa1AuthorAuthorAuthorAuthorAuthorA1', 'passed' => true, 'jury' => []],
                    ['answer' => 'cc', 'from' => 'poa1AuthorAuthorAuthorAuthorAuthorA1', 'passed' => false, 'jury' => []],
                ],
                'established_after' => ['poa1New'],
                'attestations' => ['poa1x' => 2, 'poa1y' => 3],
                'final_payout' => ['capped' => ['poa1AuthorAuthorAuthorAuthorAuthorA1' => '10']],
            ],
            'weights' => [],
            'challenges' => [],
            'propagation' => [],
            'rewards' => [
                'poa1AuthorAuthorAuthorAuthorAuthorA1' => '10',
                'poa1Zero' => '0.000000',
            ],
        ];
        return [$e7, $e8];
    }

    public function testBirthFromPriorPending(): void
    {
        $by = poa_index_closes($this->closes());
        $this->assertSame(5, poa_settled_birth_epoch('bb', 8, $by));
        $this->assertSame(6, poa_settled_birth_epoch('cc', 8, $by));
        $this->assertNull(poa_settled_birth_epoch('missing', 8, $by));
        $this->assertSame(3, poa_pending_birth_epoch(8, 5));
    }

    public function testSettlementCohortsAndPaid(): void
    {
        $rep = poa_reports_from_public($this->closes(), [
            'checkpoint' => ['height' => 9],
            'epochs_closed' => 2,
            'txs' => 4,
            'chain_id' => 'poa-fixture',
        ], [
            ['type' => 'challenge'],
            ['type' => 'grade-sealed'],
        ], [
            ['chain' => 'base-mainnet', 'height' => 11, 'anchored_at' => 't'],
            ['chain' => 'solana-mainnet', 'height' => 22, 'anchored_at' => 't2'],
        ]);
        $rows = $rep['settlement']['rows'];
        $this->assertCount(2, $rows);
        $e8 = $rows[1];
        $this->assertSame(8, $e8['epoch']);
        $this->assertSame(2, $e8['settled']);
        $this->assertSame(1, $e8['passed']);
        $this->assertSame(1, $e8['failed']);
        $this->assertSame(1, $e8['paid']);
        $this->assertSame(1, $e8['sealed']);
        $this->assertSame(1, $e8['established_after']);
        $this->assertSame(2, $e8['still_pending']);
        $byBirth = [];
        foreach ($e8['cohorts'] as $co) {
            $byBirth[$co['birth_epoch'] === null ? 'u' : (string) $co['birth_epoch']] = $co;
        }
        $this->assertSame(0, $byBirth['3']['settled'] ?? 0);
        $this->assertSame(2, $byBirth['3']['still_pending']);
        $this->assertSame(1, $byBirth['5']['passed']);
        $this->assertSame(1, $byBirth['6']['failed']);
        $this->assertSame(9, $rep['network']['checkpoint_height']);
        $this->assertSame(11, $rep['network']['last_base_anchor']['height']);
        $this->assertSame(22, $rep['network']['last_solana_anchor']['height']);
        $this->assertSame(1, $rep['network']['recent_tx_types']['challenge']);
        $this->assertSame(0, $rep['close_agreement']['closes_with_missing_keys']);
        $this->assertSame(8, $rep['grading_backlog']['as_of_epoch']);
        $this->assertSame('0.4.0', poa_changelog()[0]['version']);
    }

    public function testEmptyReports(): void
    {
        $rep = poa_reports_from_public([], [], [], []);
        $this->assertSame([], $rep['settlement']['rows']);
        $this->assertNull($rep['grading_backlog']);
    }
}

<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PoaReportsUiTest extends TestCase
{
    private function rows(): array
    {
        $out = [];
        for ($i = 0; $i < 5; $i++) {
            $out[] = [
                'epoch' => $i,
                'ts' => 100 + $i,
                'settled' => $i * 10,
                'still_pending' => 100 - $i,
                'established_after' => $i,
                'panel' => ['pending' => 100 - $i, 'with_panel' => $i * 10],
                'cohorts' => [
                    ['birth_epoch' => 0, 'passed' => 0, 'failed' => 0, 'settled' => 0, 'still_pending' => 50 - $i],
                    ['birth_epoch' => 1, 'passed' => 1, 'failed' => 0, 'settled' => 1, 'still_pending' => $i === 2 ? 9 : 3],
                ],
            ];
        }
        $out[3]['cohorts'][] = ['birth_epoch' => 2, 'passed' => 0, 'failed' => 0, 'settled' => 0, 'still_pending' => 4];
        return $out;
    }

    public function testWindowAndPage(): void
    {
        $chrono = poa_closes_chrono($this->rows());
        $this->assertSame(0, $chrono[0]['epoch']);
        $win = poa_reports_window($chrono, 3);
        $this->assertCount(3, $win);
        $this->assertSame(2, $win[0]['epoch']);
        $this->assertSame(4, $win[2]['epoch']);
        $newest = array_reverse($chrono);
        $page = poa_reports_page($newest, 0, 2);
        $this->assertSame(4, $page[0]['epoch']);
        $this->assertSame(3, $page[1]['epoch']);
        $this->assertSame(4, poa_latest_epoch($chrono));
    }

    public function testDeltasAndRate(): void
    {
        $this->assertSame(5, poa_delta(12, 7));
        $this->assertSame('+5', poa_delta_label(5));
        $this->assertSame('0', poa_delta_label(0));
        $this->assertSame('—', poa_delta_label(null));
        $head = poa_headline_from_closes($this->rows());
        $this->assertSame(4, $head['epoch']);
        $this->assertSame(40, $head['settled']);
        $this->assertSame(10, $head['settled_delta']);
        $this->assertSame(96, $head['still_pending']);
        $this->assertSame(-1, $head['still_pending_delta']);
        $this->assertNotNull($head['panel_rate']);
    }

    public function testHeatmapLeavesMissingUnlabeled(): void
    {
        $heat = poa_heatmap_still_pending($this->rows());
        $this->assertContains(0, $heat['births']);
        $this->assertContains(2, $heat['births']);
        $this->assertArrayHasKey(3, $heat['cells']['2']);
        $this->assertArrayNotHasKey(0, $heat['cells']['2']);
        $this->assertGreaterThan(0, $heat['max']);
        $this->assertSame('transparent', poa_heat_fill(0, 10));
    }

    public function testSvgHasPolyline(): void
    {
        $svg = poa_svg_dual_line(['0', '1'], [1, 3], [10, 8], 'Settled', 'Still pending');
        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('polyline', $svg);
        $this->assertStringContainsString('Settled', $svg);
        $empty = poa_svg_dual_line([], [], [], 'A', 'B');
        $this->assertStringContainsString('No close points', $empty);
    }

    public function testChangelogReportsUi(): void
    {
        $this->assertSame('0.5.0', poa_changelog()[0]['version']);
    }
}

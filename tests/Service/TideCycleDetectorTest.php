<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\ChartDataService;
use App\Service\TideCycleDetector;
use App\Util\ReadingTimeParser;
use PHPUnit\Framework\TestCase;

class TideCycleDetectorTest extends TestCase
{
    private TideCycleDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new TideCycleDetector();
    }

    public function testDetectExtremaSeriesFindsPeakAndTrough(): void
    {
        $levels = [30.0, 32.0, 35.0, 33.0, 28.0, 26.0, 29.0, 34.0];
        $times = [
            '2026-05-25 10:00:00',
            '2026-05-25 10:01:00',
            '2026-05-25 10:02:00',
            '2026-05-25 10:03:00',
            '2026-05-25 10:04:00',
            '2026-05-25 10:05:00',
            '2026-05-25 10:06:00',
            '2026-05-25 10:07:00',
        ];

        $result = $this->detector->detectExtremaSeries($levels, $times, 2.0, 0);

        $this->assertNotEmpty($result['peaks']);
        $this->assertNotEmpty($result['troughs']);
        $this->assertContains(35.0, array_column($result['peaks'], 1));
        $this->assertContains(26.0, array_column($result['troughs'], 1));
    }

    public function testDetectExtremaSeriesFindsSlowTides(): void
    {
        // Marée lente : deltas de 0.5 cm par lecture (sous le seuil de 2 cm),
        // mais amplitude totale de 4 cm — doit être détectée.
        $levels = [];
        $times = [];
        $base = strtotime('2026-05-25 10:00:00');
        $profile = array_merge(range(30.0, 34.0, 0.5), range(33.5, 30.0, 0.5), range(30.5, 34.0, 0.5));
        foreach ($profile as $i => $level) {
            $levels[] = $level;
            $times[] = date('Y-m-d H:i:s', $base + $i * 60);
        }

        $result = $this->detector->detectExtremaSeries($levels, $times, 2.0, 0);

        $this->assertNotEmpty($result['peaks']);
        $this->assertNotEmpty($result['troughs']);
        $this->assertContains(34.0, array_column($result['peaks'], 1));
        $this->assertContains(30.0, array_column($result['troughs'], 1));
    }

    public function testDetectExtremaSeriesReturnsEmptyWhenInsufficientData(): void
    {
        $result = $this->detector->detectExtremaSeries([30.0, 31.0], ['2026-05-25 10:00:00', '2026-05-25 10:01:00']);

        $this->assertSame([], $result['peaks']);
        $this->assertSame([], $result['troughs']);
    }

    public function testTimestampMatchesChartDataService(): void
    {
        $time = '2026-06-05 14:30:00';
        $expectedMs = ReadingTimeParser::toUnixMs($time);

        $levels = [30.0, 32.0, 35.0, 33.0, 28.0];
        $times = [
            '2026-06-05 14:26:00',
            '2026-06-05 14:27:00',
            '2026-06-05 14:28:00',
            '2026-06-05 14:29:00',
            $time,
        ];
        $result = $this->detector->detectExtremaSeries($levels, $times, 2.0, 0);

        $chartService = new ChartDataService();
        $readingTimeJson = $chartService->prepareTimestamps([
            ['reading_time' => $times[4]],
            ['reading_time' => $times[3]],
            ['reading_time' => $times[2]],
            ['reading_time' => $times[1]],
            ['reading_time' => $times[0]],
        ]);
        $chartTimestamps = json_decode($readingTimeJson, true);
        $lastChartTs = end($chartTimestamps);

        $this->assertSame($expectedMs, $lastChartTs);
        $this->assertNotNull($expectedMs);

        $allMarkerTs = array_merge(
            array_column($result['peaks'], 0),
            array_column($result['troughs'], 0)
        );
        foreach ($allMarkerTs as $ts) {
            $this->assertContains(
                $ts,
                $chartTimestamps,
                'Chaque marqueur doit aligner un timestamp du graphique'
            );
        }
    }

    public function testDetectExtremaSeriesFlushesFinalExtreme(): void
    {
        $levels = [30.0, 32.0, 35.0, 37.0];
        $times = [
            '2026-05-25 10:00:00',
            '2026-05-25 10:01:00',
            '2026-05-25 10:02:00',
            '2026-05-25 10:03:00',
        ];

        $result = $this->detector->detectExtremaSeries($levels, $times, 2.0, 0);

        $this->assertNotEmpty($result['peaks']);
        $peakValues = array_column($result['peaks'], 1);
        $this->assertContains(37.0, $peakValues);
    }

    public function testDetectCyclesFindsCompleteCycles(): void
    {
        $levels = [30.0, 35.0, 40.0, 35.0, 30.0, 35.0];
        $times = [
            '2026-05-25 10:00:00',
            '2026-05-25 10:10:00',
            '2026-05-25 10:20:00',
            '2026-05-25 10:30:00',
            '2026-05-25 10:40:00',
            '2026-05-25 10:50:00',
        ];

        $result = $this->detector->detectCycles($levels, $times, 2.0);

        $this->assertGreaterThanOrEqual(1, $result['cycles']);
        $this->assertNotEmpty($result['amplitudes']);
    }

    public function testDetectCyclesUpdatesMinMaxOnSmallDeltas(): void
    {
        $levels = [30.0, 30.5, 35.0, 34.5, 28.0, 27.5, 32.0];
        $times = [
            '2026-05-25 10:00:00',
            '2026-05-25 10:05:00',
            '2026-05-25 10:10:00',
            '2026-05-25 10:15:00',
            '2026-05-25 10:20:00',
            '2026-05-25 10:25:00',
            '2026-05-25 10:30:00',
        ];

        $result = $this->detector->detectCycles($levels, $times, 2.0);

        $this->assertGreaterThanOrEqual(1, $result['cycles']);
        if ($result['amplitudes'] !== []) {
            $this->assertGreaterThanOrEqual(5.0, max($result['amplitudes']));
        }
    }

    public function testDetectCyclesFindsSlowCycles(): void
    {
        // Cycle lent : montée puis descente par paliers de 0.4 cm (sous le seuil),
        // amplitude totale 8 cm — un cycle complet doit être détecté.
        $levels = array_merge(range(30.0, 38.0, 0.4), range(37.6, 30.0, 0.4));
        $times = [];
        $base = strtotime('2026-05-25 10:00:00');
        foreach (array_keys($levels) as $i) {
            $times[] = date('Y-m-d H:i:s', $base + $i * 60);
        }

        $result = $this->detector->detectCycles($levels, $times, 2.0);

        $this->assertGreaterThanOrEqual(1, $result['cycles']);
        $this->assertGreaterThanOrEqual(7.0, max($result['amplitudes']));
        $this->assertNotEmpty($result['cycleDurations']);
    }

    public function testDetectCurrentTrendOnSlowVariation(): void
    {
        // Descente lente de la distance (eau qui monte) : deltas de 0.5 cm
        $levels = range(40.0, 34.0, 0.5);
        $this->assertSame('rising', $this->detector->detectCurrentTrend($levels, 2.0));

        // Montée lente de la distance (eau qui descend)
        $levels = range(30.0, 36.0, 0.5);
        $this->assertSame('falling', $this->detector->detectCurrentTrend($levels, 2.0));
    }

    public function testComputeVariationsAccumulatesSlowDrift(): void
    {
        // Dérive lente : -0.3 cm par lecture, 6 cm au total (seuil 1 cm)
        $levels = range(50.0, 44.0, 0.3);
        $result = $this->detector->computeVariations(array_values($levels), 1.0);

        $this->assertGreaterThanOrEqual(5.0, $result['negative']);
        $this->assertSame(0.0, $result['positive']);
        $this->assertEqualsWithDelta(-6.0, $result['global'], 0.001);
    }

    public function testDetectCurrentTrend(): void
    {
        $rising = [40.0, 38.0, 35.0, 32.0];
        $this->assertSame('rising', $this->detector->detectCurrentTrend($rising, 2.0));

        $falling = [30.0, 33.0, 36.0, 39.0];
        $this->assertSame('falling', $this->detector->detectCurrentTrend($falling, 2.0));

        $this->assertSame('stable', $this->detector->detectCurrentTrend([30.0, 35.0, 30.0], 2.0));

        $this->assertNull($this->detector->detectCurrentTrend([30.0], 2.0));
    }

    public function testTrendLabel(): void
    {
        $this->assertSame('Eau en montée', TideCycleDetector::trendLabel('rising'));
        $this->assertSame('Eau en descente', TideCycleDetector::trendLabel('falling'));
        $this->assertSame('Stable', TideCycleDetector::trendLabel('stable'));
        $this->assertNull(TideCycleDetector::trendLabel(null));
    }

    /**
     * La passe zigzag partagée (analyzeSeries) réinjectée dans detectCycles /
     * detectCurrentTrend / computeVariations doit donner exactement le même résultat
     * que les appels autonomes (qui recalculent le zigzag en interne).
     */
    public function testAnalyzeSeriesSharedPassMatchesStandaloneCalls(): void
    {
        $levels = [30.0, 35.0, 40.0, 35.0, 30.0, 35.0, 38.0, 33.0, 28.0];
        $times = [
            '2026-05-25 10:00:00',
            '2026-05-25 10:10:00',
            '2026-05-25 10:20:00',
            '2026-05-25 10:30:00',
            '2026-05-25 10:40:00',
            '2026-05-25 10:50:00',
            '2026-05-25 11:00:00',
            '2026-05-25 11:10:00',
            '2026-05-25 11:20:00',
        ];
        $threshold = 2.0;

        $zigzag = $this->detector->analyzeSeries($levels, $threshold);

        // detectCycles : autonome vs zigzag pré-calculé.
        $cyclesStandalone = $this->detector->detectCycles($levels, $times, $threshold);
        $cyclesShared = $this->detector->detectCycles($levels, $times, $threshold, $zigzag);
        $this->assertSame($cyclesStandalone, $cyclesShared);

        // detectCurrentTrend : autonome vs zigzag pré-calculé.
        $this->assertSame(
            $this->detector->detectCurrentTrend($levels, $threshold),
            $this->detector->detectCurrentTrend($levels, $threshold, $zigzag)
        );

        // computeVariations : autonome vs zigzag pré-calculé (même seuil).
        $this->assertSame(
            $this->detector->computeVariations($levels, $threshold),
            $this->detector->computeVariations($levels, $threshold, $zigzag)
        );
    }
}

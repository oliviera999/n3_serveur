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
        $this->assertSame(35.0, $result['peaks'][0][1]);
        $this->assertSame(26.0, $result['troughs'][0][1]);
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
}

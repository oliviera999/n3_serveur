<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\TideCycleDetector;
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
}

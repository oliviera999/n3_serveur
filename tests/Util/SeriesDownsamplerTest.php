<?php

declare(strict_types=1);

namespace Tests\Util;

use App\Util\SeriesDownsampler;
use PHPUnit\Framework\TestCase;

class SeriesDownsamplerTest extends TestCase
{
    /**
     * @return array{0: list<int>, 1: list<float>}
     */
    private function makeSeries(int $n): array
    {
        $x = [];
        $y = [];
        for ($i = 0; $i < $n; $i++) {
            $x[] = $i;
            $y[] = sin($i / 50.0) * 100.0;
        }

        return [$x, $y];
    }

    public function testSelectIndicesIdentityBelowCap(): void
    {
        [$x, $y] = $this->makeSeries(100);
        $indices = SeriesDownsampler::selectIndices($x, $y, 2000);

        $this->assertSame(range(0, 99), $indices);
    }

    public function testSelectIndicesNeverExceedsCap(): void
    {
        [$x, $y] = $this->makeSeries(4900);
        $indices = SeriesDownsampler::selectIndices($x, $y, 2000);

        $this->assertLessThanOrEqual(2000, count($indices));
    }

    public function testSelectIndicesPreservesFirstAndLast(): void
    {
        [$x, $y] = $this->makeSeries(4900);
        $indices = SeriesDownsampler::selectIndices($x, $y, 1000);

        $this->assertSame(0, $indices[0]);
        $this->assertSame(4899, $indices[count($indices) - 1]);
    }

    public function testSelectIndicesMonotonicAndInBounds(): void
    {
        [$x, $y] = $this->makeSeries(3333);
        $indices = SeriesDownsampler::selectIndices($x, $y, 500);

        for ($i = 1; $i < count($indices); $i++) {
            $this->assertGreaterThan($indices[$i - 1], $indices[$i]);
        }
        foreach ($indices as $idx) {
            $this->assertGreaterThanOrEqual(0, $idx);
            $this->assertLessThan(3333, $idx);
        }
    }

    public function testFallsBackToUniformWhenAxisNonNumeric(): void
    {
        $x = array_fill(0, 100, null);
        $y = array_fill(0, 100, null);
        $indices = SeriesDownsampler::selectIndices($x, $y, 10);

        $this->assertLessThanOrEqual(10, count($indices));
        $this->assertSame(0, $indices[0]);
        $this->assertSame(99, $indices[count($indices) - 1]);
        for ($i = 1; $i < count($indices); $i++) {
            $this->assertGreaterThan($indices[$i - 1], $indices[$i]);
        }
    }

    public function testDownsampleReadingsAlignsAllColumns(): void
    {
        $readings = [];
        for ($i = 0; $i < 3000; $i++) {
            $readings[] = [
                'reading_time' => '2025-01-01 00:00:00',
                'a' => $i,
                'b' => $i * 2,
            ];
        }

        $out = SeriesDownsampler::downsampleReadings($readings, 'reading_time', 'a', 500);

        $this->assertLessThanOrEqual(500, count($out));
        // Premier et dernier préservés.
        $this->assertSame(0, $out[0]['a']);
        $this->assertSame(2999, $out[count($out) - 1]['a']);
        // Cohérence inter-colonnes : b = 2*a sur chaque ligne conservée.
        foreach ($out as $row) {
            $this->assertSame($row['a'] * 2, $row['b']);
        }
    }

    public function testDownsampleReadingsIdentityBelowCap(): void
    {
        $readings = [
            ['reading_time' => 't0', 'a' => 1],
            ['reading_time' => 't1', 'a' => 2],
        ];

        $this->assertSame($readings, SeriesDownsampler::downsampleReadings($readings, 'reading_time', 'a', 2000));
    }

    public function testApplyIndices(): void
    {
        $values = [10, 20, 30, 40, 50];
        $this->assertSame([10, 30, 50], SeriesDownsampler::applyIndices($values, [0, 2, 4]));
    }
}

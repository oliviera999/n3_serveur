<?php

declare(strict_types=1);

namespace Tests\Domain;

use App\Domain\TideStatistics;
use PHPUnit\Framework\TestCase;

class TideStatisticsTest extends TestCase
{
    // ----------------------------------------------------------------
    // frequencyStats
    // ----------------------------------------------------------------

    public function testFrequencyStatsNominal(): void
    {
        // 4 cycles sur 8 h → 0.5 cycle/h
        $result = TideStatistics::frequencyStats([2.0, 2.0, 2.0, 2.0], 4, 8.0);

        $this->assertSame(0.5, $result['frequency']);
        // Durées identiques → fréquences identiques → écart-type nul.
        $this->assertNotNull($result['frequencyStddev']);
        $this->assertEqualsWithDelta(0.0, $result['frequencyStddev'], 1e-9);
    }

    public function testFrequencyStatsStddevWithVaryingDurations(): void
    {
        $result = TideStatistics::frequencyStats([1.0, 2.0], 2, 4.0);

        $this->assertSame(0.5, $result['frequency']);
        // Fréquences 1.0 et 0.5, moyenne 0.75, variance population = 0.0625 → stddev 0.25
        $this->assertNotNull($result['frequencyStddev']);
        $this->assertEqualsWithDelta(0.25, $result['frequencyStddev'], 1e-9);
    }

    public function testFrequencyStatsZeroDurationIsTreatedAsZeroFrequency(): void
    {
        // Une durée nulle → fréquence 0 dans le calcul d'écart-type (pas de division par 0).
        $result = TideStatistics::frequencyStats([0.0, 2.0], 2, 4.0);

        $this->assertNotNull($result['frequencyStddev']);
        // Fréquences 0 et 0.5 → moyenne 0.25, variance pop 0.0625 → stddev 0.25
        $this->assertEqualsWithDelta(0.25, $result['frequencyStddev'], 1e-9);
    }

    public function testFrequencyStatsNullWhenNoTimeOrNoCycle(): void
    {
        $this->assertSame(
            ['frequency' => null, 'frequencyStddev' => null],
            TideStatistics::frequencyStats([], 0, 0.0)
        );
        $this->assertNull(TideStatistics::frequencyStats([], 3, 0.0)['frequency']);
        $this->assertNull(TideStatistics::frequencyStats([], 0, 5.0)['frequency']);
    }

    public function testFrequencyStatsStddevNullWithSingleDuration(): void
    {
        $result = TideStatistics::frequencyStats([2.0], 1, 4.0);

        $this->assertSame(0.25, $result['frequency']);
        $this->assertNull($result['frequencyStddev']);
    }

    // ----------------------------------------------------------------
    // marnageStats
    // ----------------------------------------------------------------

    public function testMarnageStatsNominal(): void
    {
        $result = TideStatistics::marnageStats([4.0, 6.0]);

        $this->assertSame(5.0, $result['marnage']);
        // Variance population de [4,6] = 1 → stddev 1
        $this->assertNotNull($result['marnageStddev']);
        $this->assertEqualsWithDelta(1.0, $result['marnageStddev'], 1e-9);
    }

    public function testMarnageStatsEmpty(): void
    {
        $this->assertSame(
            ['marnage' => null, 'marnageStddev' => null],
            TideStatistics::marnageStats([])
        );
    }

    public function testMarnageStatsSingleHasNoStddev(): void
    {
        $result = TideStatistics::marnageStats([7.5]);

        $this->assertSame(7.5, $result['marnage']);
        $this->assertNull($result['marnageStddev']);
    }

    // ----------------------------------------------------------------
    // aggregateSwings
    // ----------------------------------------------------------------

    public function testAggregateSwingsSeparatesUpAndDown(): void
    {
        // 30 → 40 (+10) → 35 (-5) → 38 (+3)
        $points = [['value' => 30.0], ['value' => 40.0], ['value' => 35.0], ['value' => 38.0]];
        $result = TideStatistics::aggregateSwings($points);

        $this->assertEqualsWithDelta(13.0, $result['positive'], 1e-9);
        $this->assertEqualsWithDelta(5.0, $result['negative'], 1e-9);
    }

    public function testAggregateSwingsEmptyOrSinglePoint(): void
    {
        $this->assertSame(['positive' => 0.0, 'negative' => 0.0], TideStatistics::aggregateSwings([]));
        $this->assertSame(['positive' => 0.0, 'negative' => 0.0], TideStatistics::aggregateSwings([['value' => 12.0]]));
    }

    public function testAggregateSwingsIgnoresFlatDeltas(): void
    {
        $points = [['value' => 20.0], ['value' => 20.0], ['value' => 25.0]];
        $result = TideStatistics::aggregateSwings($points);

        $this->assertEqualsWithDelta(5.0, $result['positive'], 1e-9);
        $this->assertSame(0.0, $result['negative']);
    }

    public function testAggregateSwingsReindexesNonSequentialKeys(): void
    {
        // Clés non séquentielles : doit être normalisé avant l'itération.
        $points = [5 => ['value' => 10.0], 9 => ['value' => 14.0]];
        $result = TideStatistics::aggregateSwings($points);

        $this->assertEqualsWithDelta(4.0, $result['positive'], 1e-9);
        $this->assertSame(0.0, $result['negative']);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Util;

use App\Util\MathUtils;
use PHPUnit\Framework\TestCase;

class MathUtilsTest extends TestCase
{
    public function testMean(): void
    {
        $this->assertSame(2.0, MathUtils::mean([1, 2, 3]));
        $this->assertNull(MathUtils::mean([]));
    }

    public function testVarianceIsPopulationVariance(): void
    {
        // Variance de POPULATION (division par n) : pour [2, 4, 6], moyenne = 4,
        // somme des carrés des écarts = 4 + 0 + 4 = 8, /3 ≈ 2.6667.
        $this->assertEqualsWithDelta(8.0 / 3.0, MathUtils::variance([2, 4, 6]), 1e-9);
    }

    public function testVarianceMatchesArrayMapPowReference(): void
    {
        // Garantit que la boucle ($d*$d) donne le même résultat que array_map + pow.
        $values = [1.5, 2.25, -3.0, 7, 0, 4.4];
        $count = count($values);
        $mean = array_sum($values) / $count;
        $expected = array_sum(array_map(static fn ($x) => pow($x - $mean, 2), $values)) / $count;

        $this->assertEqualsWithDelta($expected, MathUtils::variance($values), 1e-12);
    }

    public function testVarianceReturnsNullForLessThanTwoValues(): void
    {
        $this->assertNull(MathUtils::variance([]));
        $this->assertNull(MathUtils::variance([42]));
    }

    public function testStandardDeviation(): void
    {
        $this->assertEqualsWithDelta(sqrt(8.0 / 3.0), MathUtils::standardDeviation([2, 4, 6]), 1e-9);
        $this->assertNull(MathUtils::standardDeviation([1]));
    }

    public function testMinMax(): void
    {
        $this->assertSame(1.0, MathUtils::min([3, 1, 2]));
        $this->assertSame(3.0, MathUtils::max([3, 1, 2]));
        $this->assertNull(MathUtils::min([]));
        $this->assertNull(MathUtils::max([]));
    }

    public function testFilterValid(): void
    {
        $this->assertSame([1, 2, 0], MathUtils::filterValid([1, null, 2, '', 0]));
    }
}

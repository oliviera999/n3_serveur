<?php

declare(strict_types=1);

namespace Tests\Util;

use App\Util\MathUtils;
use PHPUnit\Framework\TestCase;

class MathUtilsTest extends TestCase
{
    public function testLinearRegressionFitsPerfectLine(): void
    {
        // y = 2x + 1
        $result = MathUtils::linearRegression([0, 1, 2, 3], [1, 3, 5, 7]);

        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(2.0, $result['slope'], 1e-9);
        $this->assertEqualsWithDelta(1.0, $result['intercept'], 1e-9);
    }

    public function testLinearRegressionDetectsNegativeSlope(): void
    {
        $result = MathUtils::linearRegression([0, 1, 2], [10, 8, 6]);

        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(-2.0, $result['slope'], 1e-9);
    }

    public function testLinearRegressionReturnsNullWhenTooFewPoints(): void
    {
        $this->assertNull(MathUtils::linearRegression([1], [2]));
    }

    public function testLinearRegressionReturnsNullWhenAllXIdentical(): void
    {
        $this->assertNull(MathUtils::linearRegression([5, 5, 5], [1, 2, 3]));
    }

    public function testLinearRegressionReturnsNullOnLengthMismatch(): void
    {
        $this->assertNull(MathUtils::linearRegression([0, 1, 2], [1, 2]));
    }
}

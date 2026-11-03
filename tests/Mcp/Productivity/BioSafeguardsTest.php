<?php

declare(strict_types=1);

namespace Tests\Mcp\Productivity;

use App\Mcp\Control\BioLimits;
use App\Mcp\Control\ControlPolicy;
use App\Mcp\Control\PolicyDecision;
use PHPUnit\Framework\TestCase;

final class BioSafeguardsTest extends TestCase
{
    public function testPumpStopIsBiological(): void
    {
        $d = ControlPolicy::evaluate('ffp3', 16, 0, new BioLimits(true, 18, 28, 2, 15));
        self::assertTrue($d->isBiological());
    }

    public function testPumpForceOffIsBiological(): void
    {
        $d = ControlPolicy::evaluate('ffp3', 117, 2, new BioLimits(true, 18, 28, 2, 15));
        self::assertTrue($d->isBiological());
    }

    public function testOverrideAllowsPumpStop(): void
    {
        $d = ControlPolicy::evaluate('ffp3', 16, 0, new BioLimits(true, 18, 28, 2, 15), true);
        self::assertTrue($d->isOk());
    }

    public function testBioDisabledAllowsPumpStop(): void
    {
        $d = ControlPolicy::evaluate('ffp3', 16, 0, new BioLimits(false, 18, 28, 2, 15));
        self::assertTrue($d->isOk());
    }

    public function testHeatingOutOfConfiguredRangeIsBiological(): void
    {
        $d = ControlPolicy::evaluate('ffp3', 104, 30, new BioLimits(true, 18, 26, 2, 0));
        self::assertTrue($d->isBiological());
    }

    public function testHeatingHardBoundsRemainMaterialEvenWithOverride(): void
    {
        $d = ControlPolicy::evaluate('ffp3', 104, 5, new BioLimits(true, 18, 28, 2, 0), true);
        self::assertTrue($d->isMaterial());
    }

    public function testFeedCapIsBiological(): void
    {
        $d = ControlPolicy::evaluate('ffp3', 108, null, new BioLimits(true, 18, 28, 2, 0), false, 2);
        self::assertTrue($d->isBiological());
    }

    public function testServoOutOfRangeIsMaterialNeverOverridable(): void
    {
        $d = ControlPolicy::evaluate('msp1', 104, 10, new BioLimits(true, 18, 28, 2, 0), true);
        self::assertSame(PolicyDecision::MATERIAL, $d->kind);
    }

    public function testValidateCompatibilityStillRefusesPumpStop(): void
    {
        self::assertNotNull(ControlPolicy::validate('ffp3', 16, 0));
        self::assertNull(ControlPolicy::validate('ffp3', 16, 1));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Service\DerivedAlert;

use App\Service\DerivedAlert\LowValueAlertEvaluator;
use App\Service\DerivedAlert\LowValueDecision;
use PHPUnit\Framework\TestCase;

final class LowValueAlertEvaluatorTest extends TestCase
{
    public function testRaiseWhenBelowThresholdAndNotLatched(): void
    {
        $decision = LowValueAlertEvaluator::evaluate(90.0, 100.0, false);
        $this->assertSame(LowValueDecision::Raise, $decision);
    }

    public function testNoRetriggerWhenAlreadyLatched(): void
    {
        $decision = LowValueAlertEvaluator::evaluate(90.0, 100.0, true);
        $this->assertSame(LowValueDecision::None, $decision);
    }

    public function testNoClearInsideHysteresisBand(): void
    {
        // Seuil 100 → ré-armement à 105 (+5 %). À 103, on reste latché.
        $decision = LowValueAlertEvaluator::evaluate(103.0, 100.0, true);
        $this->assertSame(LowValueDecision::None, $decision);
    }

    public function testClearAboveHysteresis(): void
    {
        $decision = LowValueAlertEvaluator::evaluate(106.0, 100.0, true);
        $this->assertSame(LowValueDecision::Clear, $decision);
    }

    public function testNoneWhenAboveThresholdAndNotLatched(): void
    {
        $decision = LowValueAlertEvaluator::evaluate(150.0, 100.0, false);
        $this->assertSame(LowValueDecision::None, $decision);
    }

    public function testCustomClearThreshold(): void
    {
        $decision = LowValueAlertEvaluator::evaluate(111.0, 100.0, true, 110.0);
        $this->assertSame(LowValueDecision::Clear, $decision);
    }
}

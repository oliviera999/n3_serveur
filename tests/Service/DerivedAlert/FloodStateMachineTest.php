<?php

declare(strict_types=1);

namespace Tests\Service\DerivedAlert;

use App\Service\DerivedAlert\FloodStateMachine;
use PHPUnit\Framework\TestCase;

/**
 * Parité avec les tests firmware de la machine FloodAlert (ffp5cs
 * test_flood_alert) : debounce, cooldown, hystérésis de sortie.
 * Paramètres représentatifs : limFlood 80 mm, reset 100 mm, debounce 120 s,
 * cooldown 600 s, stable 180 s.
 */
final class FloodStateMachineTest extends TestCase
{
    private const LIM = 80.0;
    private const RESET = 100.0;
    private const DEBOUNCE = 120;
    private const COOLDOWN = 600;
    private const STABLE = 180;

    /**
     * @param array<string, mixed> $state
     */
    private function evaluate(array &$state, float $level, int $now): string
    {
        return FloodStateMachine::evaluate(
            $state,
            $level,
            $now,
            self::LIM,
            self::RESET,
            self::DEBOUNCE,
            self::COOLDOWN,
            self::STABLE
        );
    }

    public function testSendsEmailAfterDebounceWithCooldownOk(): void
    {
        $state = FloodStateMachine::initialState();
        $state['enterSinceTs'] = 1000;

        $decision = $this->evaluate($state, 50.0, 1120);
        $this->assertSame(FloodStateMachine::DECISION_SEND_EMAIL, $decision);
    }

    public function testNoEmailBeforeDebounce(): void
    {
        $state = FloodStateMachine::initialState();
        $state['enterSinceTs'] = 1000;

        $decision = $this->evaluate($state, 50.0, 1100); // 100 s < 120 s
        $this->assertSame(FloodStateMachine::DECISION_NONE, $decision);
    }

    public function testCooldownBlocksSecondEmail(): void
    {
        $state = FloodStateMachine::initialState();
        $state['enterSinceTs'] = 1000;
        $state['lastEmailTs'] = 1000;

        $decision = $this->evaluate($state, 50.0, 1200); // 200 s < cooldown 600 s
        $this->assertSame(FloodStateMachine::DECISION_NONE, $decision);
    }

    public function testMarkEmailSentLatchesInFlood(): void
    {
        $state = FloodStateMachine::initialState();
        FloodStateMachine::markEmailSent($state, 12345);

        $this->assertTrue($state['inFlood']);
        $this->assertSame(12345, $state['lastEmailTs']);

        // Latché : plus d'e-mail proposé tant que inFlood est vrai.
        $state['enterSinceTs'] = 1000;
        $decision = $this->evaluate($state, 50.0, 99999);
        $this->assertSame(FloodStateMachine::DECISION_NONE, $decision);
    }

    public function testExitFloodAfterStableAboveHysteresis(): void
    {
        $state = FloodStateMachine::initialState();
        $state['inFlood'] = true;
        $state['aboveResetSinceTs'] = 2000;

        $decision = $this->evaluate($state, 120.0, 2180);
        $this->assertSame(FloodStateMachine::DECISION_EXIT_FLOOD, $decision);
        $this->assertFalse($state['inFlood']);
    }

    public function testNoExitWhenAboveLimitButUnderHysteresis(): void
    {
        $state = FloodStateMachine::initialState();
        $state['inFlood'] = true;

        // 90 mm : au-dessus de limFlood (80) mais sous le seuil de reset (100).
        $decision = $this->evaluate($state, 90.0, 5000);
        $this->assertSame(FloodStateMachine::DECISION_NONE, $decision);
        $this->assertTrue($state['inFlood']);
        $this->assertSame(0, $state['aboveResetSinceTs']);
    }
}

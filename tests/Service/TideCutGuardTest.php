<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\TideCutGuard;
use PHPUnit\Framework\TestCase;

/**
 * La pompe aquarium n'est coupée que sur une donnée valide et vivante (incident du 30/10/2026 :
 * 196 coupures sur un niveau figé à 81 mm, puis silence sur une fenêtre entièrement NULL).
 */
final class TideCutGuardTest extends TestCase
{
    /**
     * @return array{rows: int, non_null: int, distinct: int, stddev: float|null}
     */
    private static function w(int $rows, int $nonNull, int $distinct, ?float $stddev): array
    {
        return ['rows' => $rows, 'non_null' => $nonNull, 'distinct' => $distinct, 'stddev' => $stddev];
    }

    public function testNormalTidesDoNotCut(): void
    {
        self::assertSame(TideCutGuard::OK, TideCutGuard::decide(self::w(60, 60, 25, 8.4), 1.0, 0.8, 0, 6));
    }

    public function testFlatButLiveSignalCutsThePump(): void
    {
        self::assertSame(TideCutGuard::CUT, TideCutGuard::decide(self::w(60, 60, 3, 0.4), 1.0, 0.8, 0, 6));
    }

    public function testFrozenValueIsASensorFaultNotATideFault(): void
    {
        // Incident : 60 × 81 mm → écart-type 0, une seule valeur distincte.
        self::assertSame(TideCutGuard::SENSOR_FROZEN, TideCutGuard::decide(self::w(60, 60, 1, 0.0), 1.0, 0.8, 0, 6));
    }

    public function testMostlyNullWindowIsInsufficientEvenWithZeroStddev(): void
    {
        // Transition figé → muet : quelques 81 mm restants au milieu des NULL (STDDEV = 0).
        self::assertSame(TideCutGuard::INSUFFICIENT_DATA, TideCutGuard::decide(self::w(60, 12, 1, 0.0), 1.0, 0.8, 0, 6));
        self::assertSame(TideCutGuard::INSUFFICIENT_DATA, TideCutGuard::decide(self::w(60, 50, 4, null), 1.0, 0.8, 0, 6));
    }

    public function testAllNullWindowIsSensorAbsent(): void
    {
        self::assertSame(TideCutGuard::SENSOR_ABSENT, TideCutGuard::decide(self::w(60, 0, 0, null), 1.0, 0.8, 0, 6));
        self::assertSame(TideCutGuard::SENSOR_ABSENT, TideCutGuard::decide(self::w(0, 0, 0, null), 1.0, 0.8, 0, 6));
    }

    public function testBreakerStopsCutsAfterDailyLimit(): void
    {
        self::assertSame(TideCutGuard::CUT, TideCutGuard::decide(self::w(60, 60, 3, 0.4), 1.0, 0.8, 5, 6));
        self::assertSame(TideCutGuard::BREAKER_OPEN, TideCutGuard::decide(self::w(60, 60, 3, 0.4), 1.0, 0.8, 6, 6));
        self::assertSame(TideCutGuard::CUT, TideCutGuard::decide(self::w(60, 60, 3, 0.4), 1.0, 0.8, 500, 0), '0 = sans limite');
    }
}

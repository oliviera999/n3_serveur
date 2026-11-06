<?php

declare(strict_types=1);

namespace Tests\Service\DerivedAlert;

use App\Service\DerivedAlert\SensorPresenceEvaluator;
use PHPUnit\Framework\TestCase;

final class SensorPresenceEvaluatorTest extends TestCase
{
    /**
     * Lignes plus récentes d'abord, espacées de 10 min.
     *
     * @param list<float|null> $values
     * @return list<array{value: mixed, reading_time: string}>
     */
    private static function rows(array $values): array
    {
        $out = [];
        foreach ($values as $i => $v) {
            $out[] = ['value' => $v, 'reading_time' => date('Y-m-d H:i:s', 1_800_000_000 - $i * 600)];
        }

        return $out;
    }

    public function testVaryingLevelIsOk(): void
    {
        $r = SensorPresenceEvaluator::evaluate(self::rows([95, 101, 108, 99, 93, 97]), 6, 12, 7200);
        self::assertSame('ok', $r['status']);
        self::assertSame(95.0, $r['last_value']);
    }

    public function testMutedSensorIsAbsentWithItsLastValue(): void
    {
        $r = SensorPresenceEvaluator::evaluate(self::rows(array_merge(array_fill(0, 6, null), [81, 81])), 6, 12, 7200);
        self::assertSame('absent', $r['status']);
        self::assertSame(6, $r['readings']);
        self::assertSame(81.0, $r['last_value']);
        self::assertSame(date('Y-m-d H:i:s', 1_800_000_000 - 5 * 600), $r['since']);
    }

    public function testFewNullsAreNotYetAbsent(): void
    {
        $r = SensorPresenceEvaluator::evaluate(self::rows([null, null, 95, 101, 108]), 6, 12, 7200);
        self::assertSame('ok', $r['status']);
    }

    public function testRepeatedValueOverTwoHoursIsFrozen(): void
    {
        // 14 × 81 mm sur 130 min, précédés d'une vraie valeur : figé.
        $r = SensorPresenceEvaluator::evaluate(self::rows(array_merge(array_fill(0, 14, 81.0), [95.0])), 6, 12, 7200);
        self::assertSame('frozen', $r['status']);
        self::assertSame(14, $r['readings']);
        self::assertSame(date('Y-m-d H:i:s', 1_800_000_000 - 13 * 600), $r['since']);
    }

    public function testRepeatedValueTooShortIsOk(): void
    {
        // 12 mesures identiques mais seulement 110 min : pas encore figé (seuil 2 h).
        $r = SensorPresenceEvaluator::evaluate(self::rows(array_merge(array_fill(0, 12, 81.0), [95.0])), 6, 12, 7200);
        self::assertSame('ok', $r['status']);
    }

    public function testIsolatedNullsDoNotBreakAFrozenRun(): void
    {
        $values = array_fill(0, 16, 81.0);
        $values[0] = null;
        $values[7] = null;
        $r = SensorPresenceEvaluator::evaluate(self::rows($values), 6, 12, 7200);
        self::assertSame('frozen', $r['status']);
        self::assertSame(14, $r['readings']);
    }

    public function testEmptyWindowIsOk(): void
    {
        self::assertSame('ok', SensorPresenceEvaluator::evaluate([], 6, 12, 7200)['status']);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Repository\OutputMonitorRepository;
use App\Service\OfflineThresholdResolver;
use PHPUnit\Framework\TestCase;

/**
 * Tests du calcul de seuil hors-ligne dérivé du temps de veille en BDD (FreqWakeUp),
 * avec facteur nuit FFP3. Le repository de lecture est mocké (aucune base) : on pilote
 * les valeurs par GPIO et on vérifie la formule veille × cycles + marge (bornée).
 */
final class OfflineThresholdResolverTest extends TestCase
{
    /**
     * @param array<int, ?string> $byGpio état renvoyé par GPIO (toutes tables confondues)
     */
    private function resolver(array $byGpio): OfflineThresholdResolver
    {
        $repo = $this->createMock(OutputMonitorRepository::class);
        $repo->method('findStateByGpio')->willReturnCallback(
            static fn (string $table, int $gpio): ?string => $byGpio[$gpio] ?? null
        );

        return new OfflineThresholdResolver($repo);
    }

    public function testFfp3DayThresholdDerivesFromFreqWakeUp(): void
    {
        // FreqWakeUp (GPIO 116) = 600 s, jour -> 600 × 2 + 60 = 1260
        $resolver = $this->resolver([116 => '600']);
        self::assertSame(1260, $resolver->resolveForFamily('FFP3', 12));
    }

    public function testFfp3NightAppliesMultiplier(): void
    {
        // Nuit (22h ∈ [19,6[) : 600 × 3 = 1800 -> 1800 × 2 + 60 = 3660
        $resolver = $this->resolver([116 => '600', 126 => '3', 127 => '19', 128 => '6']);
        self::assertSame(3660, $resolver->resolveForFamily('FFP3', 22));
        self::assertSame(3660, $resolver->resolveForFamily('FFP3', 5)); // avant 6h = encore nuit
    }

    public function testFfp3NightWindowEndHourIsExclusive(): void
    {
        // 6h n'est pas nuit (fin exclusive) -> jour -> 1260
        $resolver = $this->resolver([116 => '600', 126 => '3', 127 => '19', 128 => '6']);
        self::assertSame(1260, $resolver->resolveForFamily('FFP3', 6));
    }

    public function testSensorFamiliesIgnoreNightAndReadGpio107(): void
    {
        // N3PP / MSP1 : FreqWakeUp GPIO 107 = 300 -> 300 × 2 + 60 = 660 ; nuit ignorée même à 22h
        $resolver = $this->resolver([107 => '300', 126 => '3', 127 => '19', 128 => '6']);
        self::assertSame(660, $resolver->resolveForFamily('N3PP', 22));
        self::assertSame(660, $resolver->resolveForFamily('MSP1', 22));
    }

    public function testFallsBackToDefaultWhenFreqAbsent(): void
    {
        // Aucune valeur en BDD -> veille par défaut 600 -> 1260 (jour)
        $resolver = $this->resolver([]);
        self::assertSame(1260, $resolver->resolveForFamily('FFP3', 12));
    }

    public function testThresholdIsUpperBounded(): void
    {
        // Veille démesurée -> plafonnée à 86400 s (24 h)
        $resolver = $this->resolver([116 => '80000']);
        self::assertSame(86400, $resolver->resolveForFamily('FFP3', 12));
    }

    public function testInvalidNightMultiplierFallsBackToDefault(): void
    {
        // Multiplicateur invalide (0) -> défaut 3 -> nuit -> 3660
        $resolver = $this->resolver([116 => '600', 126 => '0', 127 => '19', 128 => '6']);
        self::assertSame(3660, $resolver->resolveForFamily('FFP3', 22));
    }

    public function testUnknownFamilyUsesSafeFallback(): void
    {
        $resolver = $this->resolver([]);
        self::assertSame(1260, $resolver->resolveForFamily('UNKNOWN', 12));
    }
}

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

    public function testFfp3NightAppliesMultiplierAndExtraToleranceCycle(): void
    {
        // Nuit (22h ∈ [19,6[) : veille 600 × 3 = 1800, tolérance nuit = 2 + 1 = 3
        // -> 1800 × 3 + 60 = 5460
        $resolver = $this->resolver([116 => '600', 126 => '3', 127 => '19', 128 => '6']);
        self::assertSame(5460, $resolver->resolveForFamily('FFP3', 22));
        self::assertSame(5460, $resolver->resolveForFamily('FFP3', 5)); // avant 6h = encore nuit
    }

    public function testFfp3MorningGraceKeepsNightThresholdAfterWindowEnds(): void
    {
        // 6h est hors fenêtre stricte [19,6[, mais le dernier cycle nuit (600 × 3 = 1800 s
        // ≈ 1 h de grâce) déborde -> régime nuit maintenu -> 5460 (et non le seuil jour 1260).
        $resolver = $this->resolver([116 => '600', 126 => '3', 127 => '19', 128 => '6']);
        self::assertSame(5460, $resolver->resolveForFamily('FFP3', 6));
        // Une fois la grâce écoulée (7h, grâce = 1 h), retour au régime jour -> 1260.
        self::assertSame(1260, $resolver->resolveForFamily('FFP3', 7));
    }

    public function testFfp3RealScenarioFreqWakeUp1800(): void
    {
        // Cas signalé : veille 1800 s. Jour -> 1800 × 2 + 60 = 3660 (61 min).
        // Nuit : 1800 × 3 = 5400 s (90 min), grâce = ceil(5400/3600) = 2 h,
        // tolérance nuit = 3 -> 5400 × 3 + 60 = 16260 s (4 h 31).
        $resolver = $this->resolver([116 => '1800', 126 => '3', 127 => '19', 128 => '6']);
        self::assertSame(3660, $resolver->resolveForFamily('FFP3', 12));   // jour
        self::assertSame(16260, $resolver->resolveForFamily('FFP3', 3));   // 3h50 -> nuit
        self::assertSame(16260, $resolver->resolveForFamily('FFP3', 22));  // 22h40 -> nuit
        self::assertSame(16260, $resolver->resolveForFamily('FFP3', 6));   // 6h50 -> grâce matinale
        self::assertSame(3660, $resolver->resolveForFamily('FFP3', 8));    // grâce écoulée -> jour
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
        // Multiplicateur invalide (0) -> défaut 3 -> nuit (tolérance 3) -> 5460
        $resolver = $this->resolver([116 => '600', 126 => '0', 127 => '19', 128 => '6']);
        self::assertSame(5460, $resolver->resolveForFamily('FFP3', 22));
    }

    public function testUnknownFamilyUsesSafeFallback(): void
    {
        $resolver = $this->resolver([]);
        self::assertSame(1260, $resolver->resolveForFamily('UNKNOWN', 12));
    }
}

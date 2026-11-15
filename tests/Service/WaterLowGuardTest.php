<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\WaterLowGuard;
use PHPUnit\Framework\TestCase;

final class WaterLowGuardTest extends TestCase
{
    /**
     * @param list<float|null> $levels
     * @return list<array<string, mixed>>
     */
    private static function rows(array $levels, ?string $status = 'ok', int $timeouts = 0): array
    {
        return array_map(
            static fn (?float $l): array => ['EauAquarium' => $l, 'wlAquaStatus' => $l === null ? 'no_echo' : $status, 'wlAquaTimeouts' => $l === null ? 4 : $timeouts],
            $levels
        );
    }

    public function testReliableConfirmedLowLevel(): void
    {
        $r = WaterLowGuard::assess(self::rows(array_fill(0, 24, 210.0)), 150.0);
        self::assertSame(WaterLowGuard::LOW, $r['decision']);
        self::assertSame([], $r['reasons']);
    }

    public function testNormalAndNoData(): void
    {
        self::assertSame(WaterLowGuard::NORMAL, WaterLowGuard::assess(self::rows([120.0, null, null]), 150.0)['decision']);
        self::assertSame(WaterLowGuard::NO_DATA, WaterLowGuard::assess(self::rows([null, 210.0]), 150.0)['decision']);
        self::assertSame(WaterLowGuard::NO_DATA, WaterLowGuard::assess([], 150.0)['decision']);
    }

    public function testUnreliableReasons(): void
    {
        $lowCoverage = WaterLowGuard::assess(self::rows([210.0, 211.0, 212.0, null, null, 210.0, null, 209.0, null, null]), 150.0);
        self::assertSame(WaterLowGuard::UNRELIABLE, $lowCoverage['decision']);
        self::assertStringContainsString('couverture', implode(' ', $lowCoverage['reasons']));
        self::assertStringContainsString('no_echo', implode(' ', $lowCoverage['reasons']));

        $timeouts = WaterLowGuard::assess(self::rows(array_fill(0, 24, 250.0), 'ok', 2), 150.0);
        self::assertSame(WaterLowGuard::UNRELIABLE, $timeouts['decision']);
        self::assertStringContainsString('wlAquaTimeouts', implode(' ', $timeouts['reasons']));

        $unconfirmed = WaterLowGuard::assess(self::rows([152.0, 149.0, 152.0, 148.0]), 150.0);
        self::assertSame(WaterLowGuard::UNRELIABLE, $unconfirmed['decision']);
        self::assertStringContainsString('non confirmé', implode(' ', $unconfirmed['reasons']));
    }

    /**
     * Firmware sans diagnostic d'écho (< 15.33) : statut absent = pas un défaut.
     */
    public function testMissingEchoDiagnosticIsNotAFault(): void
    {
        $rows = array_map(static fn (): array => ['EauAquarium' => 210.0], range(1, 12));
        self::assertSame(WaterLowGuard::LOW, WaterLowGuard::assess($rows, 150.0)['decision']);
    }
}

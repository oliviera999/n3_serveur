<?php

declare(strict_types=1);

namespace Tests\Service\Realtime;

use App\Service\Realtime\RealtimeHealthTrait;
use PHPUnit\Framework\TestCase;

/**
 * Garde-fous de robustesse du calcul de santé temps réel (constat S10, 6.34.0).
 * Les appelants passent aujourd'hui des constantes saines : ces cas verrouillent le
 * comportement pour les futurs.
 */
final class RealtimeHealthTraitTest extends TestCase
{
    private RealtimeHealthProbe $probe;

    protected function setUp(): void
    {
        $this->probe = new RealtimeHealthProbe();
    }

    public function testUptimePercentageIsCappedAtHundred(): void
    {
        $this->assertSame(100.0, $this->probe->uptime(500.0, 100.0));
        $this->assertSame(50.0, $this->probe->uptime(50.0, 100.0));
        $this->assertSame(0.0, $this->probe->uptime(10.0, 0.0));
    }

    /**
     * Un intervalle nul lèverait DivisionByZeroError — fatale en PHP 8.
     */
    public function testZeroIntervalDoesNotDivideByZero(): void
    {
        $this->assertSame(0.0, $this->probe->sensorUptime(7, 0, 1000));
        $this->assertSame(0.0, $this->probe->sensorUptime(7, -5, 1000));
    }

    public function testSensorUptimeNominalCase(): void
    {
        // 1 jour, 1 lecture / 2 min => 720 attendues ; 360 reçues => 50 %.
        $this->assertSame(50.0, $this->probe->sensorUptime(1, 2, 360));
    }

    /**
     * Une date illisible donnait `time() - false` = `time() - 0`, soit une durée de
     * fonctionnement d'environ 56 ans. Une date illisible = absence d'information.
     */
    public function testUnparsableFirstReadingDateYieldsNull(): void
    {
        $this->assertNull($this->probe->moduleUptime('pas-une-date'));
        $this->assertNull($this->probe->moduleUptime(null));
    }

    public function testParsableFirstReadingDateYieldsPositiveDuration(): void
    {
        $uptime = $this->probe->moduleUptime(date('Y-m-d H:i:s', time() - 3600));

        $this->assertNotNull($uptime);
        $this->assertGreaterThanOrEqual(3599, $uptime);
        $this->assertLessThanOrEqual(3601, $uptime);
    }
}

/**
 * Expose les helpers protégés du trait.
 *
 * @internal
 */
final class RealtimeHealthProbe
{
    use RealtimeHealthTrait;

    public function uptime(float $actual, float $expected): float
    {
        return $this->uptimePercentage($actual, $expected);
    }

    public function sensorUptime(int $days, int $intervalMinutes, int $actualReadings): float
    {
        return $this->sensorUptimePercentage($days, $intervalMinutes, $actualReadings);
    }

    public function moduleUptime(?string $firstReadingDate): ?int
    {
        return $this->moduleUptimeSecondsFromDate($firstReadingDate);
    }
}

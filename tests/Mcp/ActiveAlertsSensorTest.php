<?php

declare(strict_types=1);

namespace Tests\Mcp;

use App\Mcp\Support\ActiveAlertsReader;
use PHPUnit\Framework\TestCase;

/**
 * Un capteur absent / figé (Ffp3DerivedAlertService, 6.56.0) apparaît dans les alertes EN COURS
 * (iot_alerts / iot_overview) tant que l'incident est ouvert.
 */
final class ActiveAlertsSensorTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mcp-active-sensor-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testOpenSensorIncidentsAreListedAndRecoveredOnesAreNot(): void
    {
        file_put_contents($this->dir . '/derived_alerts_ffp3.json', (string) json_encode(['sensors' => [
            'EauAquarium' => ['status' => 'absent', 'since' => '2026-10-31 22:46:37', 'last_value' => 81.0],
            'EauPotager' => ['status' => 'ok', 'since' => null],
        ]]));

        $alerts = (new ActiveAlertsReader($this->dir, $this->dir . '/no-flag'))->active();
        $keys = array_column($alerts, 'key');

        self::assertContains('ffp3:sensor-absent:EauAquarium', $keys);
        self::assertNotContains('ffp3:sensor-ok:EauPotager', $keys);
        $alert = $alerts[array_search('ffp3:sensor-absent:EauAquarium', $keys, true)];
        self::assertSame('critical', $alert['severity']);
        self::assertSame('2026-10-31 22:46:37', $alert['since']);
    }
}

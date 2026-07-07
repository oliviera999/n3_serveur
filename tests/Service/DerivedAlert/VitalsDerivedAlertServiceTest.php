<?php

declare(strict_types=1);

namespace Tests\Service\DerivedAlert;

use App\Notification\NotificationCategory;
use App\Notification\Severity;
use App\Repository\MspSensorRepository;
use App\Repository\N3ppSensorRepository;
use App\Service\DerivedAlert\DerivedAlertStateStore;
use App\Service\DerivedAlert\MspDerivedAlertService;
use App\Service\DerivedAlert\N3ppDerivedAlertService;
use App\Service\LogService;
use App\Service\NotificationService;
use PHPUnit\Framework\TestCase;

/**
 * Batterie faible + redémarrage (socle commun N3PP/MSP1) et sol sec (N3PP).
 */
final class VitalsDerivedAlertServiceTest extends TestCase
{
    private string $stateFile;

    protected function setUp(): void
    {
        putenv('LOG_FILE_PATH=php://memory');
        $this->stateFile = sys_get_temp_dir() . '/derived_vitals_test_' . uniqid('', true) . '.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->stateFile)) {
            unlink($this->stateFile);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function n3ppRow(
        int $id,
        float $pontDiv = 2000.0,
        float $seuil = 1500.0,
        int $bootCount = 10,
        float $humidMoy = 60.0,
        float $seuilSec = 30.0,
    ): array {
        return [
            'id' => $id,
            'PontDiv' => $pontDiv,
            'SeuilPontDiv' => $seuil,
            'bootCount' => $bootCount,
            'HumidMoy' => $humidMoy,
            'SeuilSec' => $seuilSec,
            'Humid1' => 55.0,
            'Humid2' => 65.0,
            'Humid3' => 0.0,
            'Humid4' => 0.0,
        ];
    }

    private function buildN3pp(NotificationService $notifier, N3ppSensorRepository $repo): N3ppDerivedAlertService
    {
        return new N3ppDerivedAlertService(
            $repo,
            $notifier,
            $this->createMock(LogService::class),
            new DerivedAlertStateStore($this->stateFile),
        );
    }

    public function testBatteryLowRaisesCriticalOnceThenRearms(): void
    {
        $repo = $this->createMock(N3ppSensorRepository::class);
        $repo->method('getLatest')->willReturnOnConsecutiveCalls(
            $this->n3ppRow(1, pontDiv: 1400.0),           // sous le seuil → alerte
            $this->n3ppRow(2, pontDiv: 1400.0),           // toujours bas, latché → rien
            $this->n3ppRow(3, pontDiv: 1600.0),           // > seuil + 5 % → ré-armement silencieux
            $this->n3ppRow(4, pontDiv: 1400.0),           // repasse sous le seuil → nouvelle alerte
        );

        $notifier = $this->createMock(NotificationService::class);
        $notifier->expects($this->exactly(2))
            ->method('sendAlert')
            ->with(
                Severity::Critical,
                NotificationCategory::Energy,
                'N3PP',
                'Batterie faible',
                $this->anything(),
                'n3pp:battery-low'
            )
            ->willReturn(true);

        $service = $this->buildN3pp($notifier, $repo);
        $service->run();
        $service->run();
        $service->run();
        $service->run();
    }

    public function testSameRowProcessedOnlyOnce(): void
    {
        $repo = $this->createMock(N3ppSensorRepository::class);
        $repo->method('getLatest')->willReturn($this->n3ppRow(7, pontDiv: 1400.0));

        $notifier = $this->createMock(NotificationService::class);
        $notifier->expects($this->once())->method('sendAlert')->willReturn(true);

        $service = $this->buildN3pp($notifier, $repo);
        $service->run();
        $service->run(); // même id de ligne → curseur, aucun retraitement
    }

    public function testRebootDetectedOnBootCountReset(): void
    {
        $repo = $this->createMock(N3ppSensorRepository::class);
        $repo->method('getLatest')->willReturnOnConsecutiveCalls(
            $this->n3ppRow(1, bootCount: 41),
            $this->n3ppRow(2, bootCount: 42),  // incrément normal (réveil) → rien
            $this->n3ppRow(3, bootCount: 1),   // reset → redémarrage détecté
        );

        $notifier = $this->createMock(NotificationService::class);
        $notifier->expects($this->once())
            ->method('sendAlert')
            ->with(
                Severity::Info,
                NotificationCategory::Lifecycle,
                'N3PP',
                'Redémarrage détecté',
                $this->stringContains('42'),
                'n3pp:reboot'
            )
            ->willReturn(true);

        $service = $this->buildN3pp($notifier, $repo);
        $service->run();
        $service->run();
        $service->run();
    }

    public function testSoilDryRaisesThenClearsWithMail(): void
    {
        $repo = $this->createMock(N3ppSensorRepository::class);
        $repo->method('getLatest')->willReturnOnConsecutiveCalls(
            $this->n3ppRow(1, humidMoy: 20.0),  // < 30 → sol sec
            $this->n3ppRow(2, humidMoy: 25.0),  // toujours sec, latché → rien
            $this->n3ppRow(3, humidMoy: 40.0),  // > 31.5 (30 + 5 %) → retour normale
        );

        $notifier = $this->createMock(NotificationService::class);
        $calls = [];
        $notifier->method('sendAlert')->willReturnCallback(
            static function (Severity $severity, NotificationCategory $category, string $family, string $subject) use (&$calls): bool {
                $calls[] = [$severity, $subject];

                return true;
            }
        );

        $service = $this->buildN3pp($notifier, $repo);
        $service->run();
        $service->run();
        $service->run();

        $this->assertCount(2, $calls);
        $this->assertSame([Severity::Alert, 'Sol sec'], $calls[0]);
        $this->assertSame([Severity::Info, 'Humidité du sol redevenue normale'], $calls[1]);
    }

    public function testSoilAlertBlockedWithoutValidSensor(): void
    {
        $row = $this->n3ppRow(1, humidMoy: 5.0);
        $row['Humid1'] = 0.0;
        $row['Humid2'] = 0.0;

        $repo = $this->createMock(N3ppSensorRepository::class);
        $repo->method('getLatest')->willReturn($row);

        $notifier = $this->createMock(NotificationService::class);
        $notifier->expects($this->never())->method('sendAlert');

        $service = $this->buildN3pp($notifier, $repo);
        $service->run();
    }

    public function testMspBatteryLowUsesMsp1Family(): void
    {
        $repo = $this->createMock(MspSensorRepository::class);
        $repo->method('getLatest')->willReturn([
            'id' => 1,
            'PontDiv' => 1200.0,
            'SeuilPontDiv' => 1500.0,
            'bootCount' => 3,
        ]);

        $notifier = $this->createMock(NotificationService::class);
        $notifier->expects($this->once())
            ->method('sendAlert')
            ->with(
                Severity::Critical,
                NotificationCategory::Energy,
                'MSP1',
                'Batterie faible',
                $this->anything(),
                'msp1:battery-low'
            )
            ->willReturn(true);

        $service = new MspDerivedAlertService(
            $repo,
            $notifier,
            $this->createMock(LogService::class),
            new DerivedAlertStateStore($this->stateFile),
        );
        $service->run();
    }
}

<?php

declare(strict_types=1);

namespace Tests\Service\DerivedAlert;

use App\Notification\NotificationCategory;
use App\Notification\Severity;
use App\Repository\OutputRepository;
use App\Repository\SensorReadRepository;
use App\Service\DerivedAlert\DerivedAlertStateStore;
use App\Service\DerivedAlert\Ffp3DerivedAlertService;
use App\Service\LogService;
use App\Service\NotificationService;
use PHPUnit\Framework\TestCase;

final class Ffp3DerivedAlertServiceTest extends TestCase
{
    private string $stateFile;
    private int $now;

    protected function setUp(): void
    {
        putenv('LOG_FILE_PATH=php://memory');
        $this->stateFile = sys_get_temp_dir() . '/derived_ffp3_test_' . uniqid('', true) . '.json';
        $this->now = time();
        unset($_ENV['FLOOD_DEBOUNCE_SEC'], $_ENV['FLOOD_COOLDOWN_SEC'], $_ENV['FLOOD_HYST_CM'], $_ENV['FLOOD_RESET_STABLE_SEC'], $_ENV['FLOOD_MAX_READING_AGE_SEC']);
    }

    protected function tearDown(): void
    {
        if (is_file($this->stateFile)) {
            unlink($this->stateFile);
        }
    }

    /**
     * @param array<string, mixed> $reading
     * @param array<int, float|null> $gpioValues
     */
    private function buildService(
        array $reading,
        NotificationService $notifier,
        array $gpioValues = [114 => 8.0, 104 => 22.0],
    ): Ffp3DerivedAlertService {
        $sensorRepo = $this->createMock(SensorReadRepository::class);
        $sensorRepo->method('getLastReadings')->willReturn($reading);

        $outputRepo = $this->createMock(OutputRepository::class);
        $outputRepo->method('findByGpio')->willReturnCallback(
            static function (int $gpio) use ($gpioValues): ?array {
                $value = $gpioValues[$gpio] ?? null;

                return $value === null ? null : ['gpio' => $gpio, 'state' => (string) $value];
            }
        );

        return new Ffp3DerivedAlertService(
            $sensorRepo,
            $outputRepo,
            $notifier,
            $this->createMock(LogService::class),
            new DerivedAlertStateStore($this->stateFile),
            fn (): int => $this->now,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function reading(float $eauAquarium, int $etatHeat = 0): array
    {
        return [
            'id' => 42,
            'EauAquarium' => $eauAquarium,
            'TempEau' => 21.5,
            'etatHeat' => $etatHeat,
            'reading_time' => date('Y-m-d H:i:s', $this->now - 30),
        ];
    }

    public function testFloodAlertSentAfterDebounce(): void
    {
        // limFlood 8 cm = 80 mm ; niveau 50 mm = trop plein.
        $notifier = $this->createMock(NotificationService::class);
        $notifier->expects($this->once())
            ->method('sendAlert')
            ->with(
                Severity::Critical,
                NotificationCategory::Hydraulic,
                'FFP3',
                'Aquarium TROP PLEIN',
                $this->stringContains('50'),
                'ffp3:flood'
            )
            ->willReturn(true);

        // Premier run : entre sous le seuil (debounce court en marche).
        $service = $this->buildService($this->reading(50.0), $notifier);
        $service->run();

        // Second run 6 min plus tard : debounce (5 min) écoulé → e-mail.
        $this->now += 360;
        $service = $this->buildService($this->reading(50.0), $notifier);
        $service->run();

        // Troisième run : latché (inFlood), pas de nouvel envoi (expects once).
        $this->now += 60;
        $service = $this->buildService($this->reading(50.0), $notifier);
        $service->run();
    }

    public function testFloodNotLatchedWhenSendFails(): void
    {
        $notifier = $this->createMock(NotificationService::class);
        // Envoi refusé (politique/cooldown/échec transport) → pas de latch → retenté.
        $notifier->expects($this->exactly(2))->method('sendAlert')->willReturn(false);

        $service = $this->buildService($this->reading(50.0), $notifier);
        $service->run();

        $this->now += 360;
        $service = $this->buildService($this->reading(50.0), $notifier);
        $service->run();

        $this->now += 60;
        $service = $this->buildService($this->reading(50.0), $notifier);
        $service->run();
    }

    public function testNoFloodAlertOnStaleReading(): void
    {
        $notifier = $this->createMock(NotificationService::class);
        $notifier->expects($this->never())->method('sendAlert');

        $reading = $this->reading(50.0);
        $reading['reading_time'] = date('Y-m-d H:i:s', $this->now - 3600); // 1 h : périmé

        $service = $this->buildService($reading, $notifier);
        $service->run();
        $this->now += 360;
        $service->run();
    }

    public function testHeaterTransitionSendsInfo(): void
    {
        $notifier = $this->createMock(NotificationService::class);
        $notifier->expects($this->once())
            ->method('sendAlert')
            ->with(
                Severity::Info,
                NotificationCategory::Environment,
                'FFP3',
                'Chauffage ON',
                $this->stringContains('21.5'),
                'ffp3:heater-on'
            )
            ->willReturn(true);

        // Niveau haut (200 mm > 80 mm) : pas de flood, on isole le chauffage.
        $service = $this->buildService($this->reading(200.0, 0), $notifier);
        $service->run(); // initialise lastState=OFF sans notifier

        $service = $this->buildService($this->reading(200.0, 1), $notifier);
        $service->run(); // transition OFF→ON → notification
    }

    public function testFirmwareUpdateSendsInfo(): void
    {
        $notifier = $this->createMock(NotificationService::class);
        $notifier->expects($this->once())
            ->method('sendAlert')
            ->with(
                Severity::Info,
                NotificationCategory::Lifecycle,
                'FFP3',
                'Firmware mis à jour',
                $this->stringContains('15.09'),
                'ffp3:fw-update:15.10'
            )
            ->willReturn(true);

        // Niveau haut (pas de flood) + chauffage constant : on isole la version.
        $readingV1 = $this->reading(200.0, 0);
        $readingV1['version'] = '15.09';
        $readingV1['sensor'] = 'ffp5cs';
        $service = $this->buildService($readingV1, $notifier);
        $service->run(); // initialise sans notifier

        $readingV2 = $this->reading(200.0, 0);
        $readingV2['version'] = '15.10';
        $readingV2['sensor'] = 'ffp5cs';
        $service = $this->buildService($readingV2, $notifier);
        $service->run(); // changement de version -> mail
        $service->run(); // même version -> pas de re-mail (expects once)
    }

    public function testHeaterNoMailWithoutTransition(): void
    {
        $notifier = $this->createMock(NotificationService::class);
        $notifier->expects($this->never())->method('sendAlert');

        $service = $this->buildService($this->reading(200.0, 1), $notifier);
        $service->run(); // init
        $service->run(); // même état → rien
    }
}

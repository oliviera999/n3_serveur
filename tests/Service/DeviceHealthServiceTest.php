<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Notification\NotificationCategory;
use App\Notification\Severity;
use App\Repository\HeartbeatMonitorRepository;
use App\Service\DeviceHealthService;
use App\Service\LogService;
use App\Service\NotificationService;
use App\Service\OfflineThresholdResolver;
use PHPUnit\Framework\TestCase;

/**
 * Tests de DeviceHealthService — supervision « appareil silencieux » généralisée
 * aux trois familles (FFP3 / N3PP / MSP1) via les tables heartbeat.
 *
 * Le repository heartbeat est mocké (aucune base) : on contrôle la dernière date par
 * famille et on vérifie le déclenchement (ou non) de l'alerte P1/Disponibilité ainsi
 * que la clé d'anti-spam par famille.
 */
final class DeviceHealthServiceTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('LOG_FILE_PATH=php://memory');
    }

    /**
     * @param array<string, ?string> $lastSeenByTable date par nom de table
     */
    private function repoReturning(array $lastSeenByTable): HeartbeatMonitorRepository
    {
        $repo = $this->createMock(HeartbeatMonitorRepository::class);
        $repo->method('getLastHeartbeatDate')->willReturnCallback(
            static fn (string $table): ?string => $lastSeenByTable[$table] ?? null
        );

        return $repo;
    }

    public function testSilentDeviceTriggersCriticalAvailabilityAlert(): void
    {
        $repo = $this->repoReturning(['n3ppHeartbeat' => date('Y-m-d H:i:s', strtotime('-3 hours'))]);

        $notifier = $this->createMock(NotificationService::class);
        $notifier->expects($this->once())
            ->method('sendAlert')
            ->with(
                Severity::Critical,
                NotificationCategory::Availability,
                'N3PP',
                $this->stringContains('silencieux'),
                $this->anything(),
                'heartbeat:offline:n3pp'
            )
            ->willReturn(true);

        $service = new DeviceHealthService(
            $repo,
            $notifier,
            $this->createMock(LogService::class),
            3600,
            [['family' => 'N3PP', 'table' => 'n3ppHeartbeat']]
        );

        self::assertSame(1, $service->checkAllFamilies());
    }

    public function testRecentHeartbeatDoesNotAlert(): void
    {
        $repo = $this->repoReturning(['msp1Heartbeat' => date('Y-m-d H:i:s', strtotime('-10 minutes'))]);

        $notifier = $this->createMock(NotificationService::class);
        $notifier->expects($this->never())->method('sendAlert');

        $service = new DeviceHealthService(
            $repo,
            $notifier,
            $this->createMock(LogService::class),
            3600,
            [['family' => 'MSP1', 'table' => 'msp1Heartbeat']]
        );

        self::assertSame(0, $service->checkAllFamilies());
    }

    public function testNeverSeenDeviceIsIgnored(): void
    {
        // Aucun heartbeat (table vide) -> famille non déployée -> pas d'alerte (anti-bruit).
        $repo = $this->repoReturning(['ffp3Heartbeat' => null]);

        $notifier = $this->createMock(NotificationService::class);
        $notifier->expects($this->never())->method('sendAlert');

        $service = new DeviceHealthService(
            $repo,
            $notifier,
            $this->createMock(LogService::class),
            3600,
            [['family' => 'FFP3', 'table' => 'ffp3Heartbeat']]
        );

        self::assertSame(0, $service->checkAllFamilies());
    }

    public function testChecksAllThreeFamiliesAndCountsOnlySilentOnes(): void
    {
        $repo = $this->repoReturning([
            'ffp3Heartbeat' => date('Y-m-d H:i:s', strtotime('-5 minutes')),   // en ligne
            'n3ppHeartbeat' => date('Y-m-d H:i:s', strtotime('-2 hours')),     // silencieux
            'msp1Heartbeat' => date('Y-m-d H:i:s', strtotime('-90 minutes')),  // silencieux
        ]);

        $notifier = $this->createMock(NotificationService::class);
        $notifier->expects($this->exactly(2))->method('sendAlert')->willReturn(true);

        $service = new DeviceHealthService(
            $repo,
            $notifier,
            $this->createMock(LogService::class),
            3600,
            [
                ['family' => 'FFP3', 'table' => 'ffp3Heartbeat'],
                ['family' => 'N3PP', 'table' => 'n3ppHeartbeat'],
                ['family' => 'MSP1', 'table' => 'msp1Heartbeat'],
            ]
        );

        self::assertSame(2, $service->checkAllFamilies());
    }

    public function testRepositoryFailureIsFailSafeNoAlert(): void
    {
        $repo = $this->createMock(HeartbeatMonitorRepository::class);
        $repo->method('getLastHeartbeatDate')->willThrowException(new \RuntimeException('DB down'));

        $notifier = $this->createMock(NotificationService::class);
        $notifier->expects($this->never())->method('sendAlert');

        $logger = $this->createMock(LogService::class);
        $logger->expects($this->atLeastOnce())->method('warning');

        $service = new DeviceHealthService(
            $repo,
            $notifier,
            $logger,
            3600,
            [['family' => 'FFP3', 'table' => 'ffp3Heartbeat']]
        );

        self::assertSame(0, $service->checkAllFamilies());
    }

    public function testThresholdFromEnvIsHonoured(): void
    {
        $previous = $_ENV['HEARTBEAT_OFFLINE_THRESHOLD_SECONDS'] ?? null;
        $_ENV['HEARTBEAT_OFFLINE_THRESHOLD_SECONDS'] = '60'; // 1 min

        try {
            // 5 min depuis le dernier heartbeat > seuil 1 min -> alerte.
            $repo = $this->repoReturning(['ffp3Heartbeat' => date('Y-m-d H:i:s', strtotime('-5 minutes'))]);
            $notifier = $this->createMock(NotificationService::class);
            $notifier->expects($this->once())->method('sendAlert')->willReturn(true);

            $service = new DeviceHealthService(
                $repo,
                $notifier,
                $this->createMock(LogService::class),
                null, // lit le seuil depuis l'env
                [['family' => 'FFP3', 'table' => 'ffp3Heartbeat']]
            );

            self::assertSame(1, $service->checkAllFamilies());
        } finally {
            if ($previous === null) {
                unset($_ENV['HEARTBEAT_OFFLINE_THRESHOLD_SECONDS']);
            } else {
                $_ENV['HEARTBEAT_OFFLINE_THRESHOLD_SECONDS'] = $previous;
            }
        }
    }

    public function testResolverThresholdOverridesFixedThreshold(): void
    {
        // Le résolveur (dérivé du temps de veille BDD) prime sur le forfait fixe.
        // Heartbeat vieux de 20 min (1200 s) : sous le forfait 3600 s (pas d'alerte),
        // mais au-dessus du seuil dérivé 660 s -> alerte attendue.
        $repo = $this->repoReturning(['n3ppHeartbeat' => date('Y-m-d H:i:s', strtotime('-20 minutes'))]);

        $resolver = $this->createMock(OfflineThresholdResolver::class);
        $resolver->method('resolveForFamily')->willReturn(660);

        $notifier = $this->createMock(NotificationService::class);
        $notifier->expects($this->once())->method('sendAlert')->willReturn(true);

        $service = new DeviceHealthService(
            $repo,
            $notifier,
            $this->createMock(LogService::class),
            3600,
            [['family' => 'N3PP', 'table' => 'n3ppHeartbeat']],
            $resolver
        );

        self::assertSame(1, $service->checkAllFamilies());
    }

    public function testResolverKeepsDeviceOnlineWithinDerivedThreshold(): void
    {
        // Heartbeat vieux de 5 min (300 s) < seuil dérivé 660 s -> pas d'alerte.
        $repo = $this->repoReturning(['n3ppHeartbeat' => date('Y-m-d H:i:s', strtotime('-5 minutes'))]);

        $resolver = $this->createMock(OfflineThresholdResolver::class);
        $resolver->method('resolveForFamily')->willReturn(660);

        $notifier = $this->createMock(NotificationService::class);
        $notifier->expects($this->never())->method('sendAlert');

        $service = new DeviceHealthService(
            $repo,
            $notifier,
            $this->createMock(LogService::class),
            60, // forfait volontairement petit : le résolveur doit primer
            [['family' => 'N3PP', 'table' => 'n3ppHeartbeat']],
            $resolver
        );

        self::assertSame(0, $service->checkAllFamilies());
    }

    public function testDefaultFamiliesCoverThreeFamilies(): void
    {
        $families = DeviceHealthService::defaultFamilies();

        $codes = array_map(static fn (array $f): string => $f['family'], $families);
        self::assertSame(['FFP3', 'N3PP', 'MSP1'], $codes);
        foreach ($families as $entry) {
            self::assertArrayHasKey('table', $entry);
            self::assertNotSame('', $entry['table']);
        }
    }
}

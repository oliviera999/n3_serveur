<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Notification\Severity;
use App\Repository\DeviceContactRepository;
use App\Service\ClockDriftAlertService;
use App\Service\LogService;
use App\Service\NotificationService;
use PHPUnit\Framework\TestCase;

/**
 * Alerte de dérive d'horloge : 3 rafales consécutives au-delà du seuil → une alerte P4 ; une rafale
 * saine parmi les trois → rien. Données calquées sur le 06/11/2026 (+2 045 s à chaque réveil).
 */
final class ClockDriftAlertServiceTest extends TestCase
{
    /** @return list<array{received_at: string, clock_offset_s: int, accepted: int, kind: string}> */
    private static function rows(array $burstOffsets, int $period = 2000): array
    {
        // Plus récent d'abord, comme recentClockOffsets(). Chaque rafale : 3 contacts à 30 s.
        $rows = [];
        $t0 = (int) strtotime('2026-11-06 16:40:00');
        foreach (array_values($burstOffsets) as $k => $offset) {
            $start = $t0 - $k * $period;
            for ($i = 0; $i < 3; $i++) {
                $rows[] = ['received_at' => date('Y-m-d H:i:s', $start + 30 * $i), 'clock_offset_s' => $offset, 'accepted' => $offset > 300 ? 0 : 1, 'kind' => 'post'];
            }
        }

        return $rows;
    }

    private function service(?NotificationService $notifier = null): ClockDriftAlertService
    {
        return new ClockDriftAlertService(
            $this->createMock(DeviceContactRepository::class),
            $notifier ?? $this->createMock(NotificationService::class),
            $this->createMock(LogService::class)
        );
    }

    public function testThreeDesynchronisedBurstsTriggerAlert(): void
    {
        $verdict = $this->service()->evaluate(self::rows([2045, 2047, 4090, 12]), 120);

        self::assertTrue($verdict['alert']);
        self::assertSame(4, $verdict['bursts']);
        self::assertSame([4090, 2047, 2045], $verdict['offsets'], 'les 3 rafales les plus récentes, de la plus ancienne à la plus récente');
        self::assertSame(4090, $verdict['max_abs_offset']);
    }

    public function testOneHealthyBurstAmongThreePreventsAlert(): void
    {
        $verdict = $this->service()->evaluate(self::rows([2045, 12, 2047]), 120);

        self::assertFalse($verdict['alert']);
    }

    public function testFewerThanThreeBurstsNeverAlert(): void
    {
        $verdict = $this->service()->evaluate(self::rows([2045, 2047]), 120);

        self::assertFalse($verdict['alert']);
        self::assertSame(2, $verdict['bursts']);
    }

    public function testNegativeOffsetsCountToo(): void
    {
        $verdict = $this->service()->evaluate(self::rows([-400, -380, -390]), 120);

        self::assertTrue($verdict['alert']);
    }

    public function testRunSendsDiagnosticAlertWithFamilyKey(): void
    {
        $repo = $this->createMock(DeviceContactRepository::class);
        $repo->method('recentClockOffsets')->willReturn(self::rows([2045, 2047, 4090]));
        $notifier = $this->createMock(NotificationService::class);
        $notifier->expects($this->once())->method('sendAlert')
            ->with(Severity::Diagnostic, $this->anything(), 'FFP3', 'Horloge du module désynchronisée', $this->stringContains('4090 s'), 'ffp3:clock-drift')
            ->willReturn(true);
        $_ENV['CLOCK_DRIFT_ALERT_SECONDS'] = '120';

        $service = new ClockDriftAlertService($repo, $notifier, $this->createMock(LogService::class));
        self::assertSame(1, $service->run([['family' => 'ffp3', 'env' => 'prod']]));
        unset($_ENV['CLOCK_DRIFT_ALERT_SECONDS']);
    }

    public function testDisabledWhenThresholdIsZero(): void
    {
        $repo = $this->createMock(DeviceContactRepository::class);
        $repo->expects($this->never())->method('recentClockOffsets');
        $_ENV['CLOCK_DRIFT_ALERT_SECONDS'] = '0';

        $service = new ClockDriftAlertService($repo, $this->createMock(NotificationService::class), $this->createMock(LogService::class));
        self::assertSame(0, $service->run([['family' => 'ffp3', 'env' => 'prod']]));
        unset($_ENV['CLOCK_DRIFT_ALERT_SECONDS']);
    }
}

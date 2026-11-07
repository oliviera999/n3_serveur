<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Notification\Severity;
use App\Repository\OtaEventRepository;
use App\Service\Availability\ContactEvidenceSource;
use App\Service\LogService;
use App\Service\NotificationService;
use App\Service\OtaSilenceAlertService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * « Module muet après téléchargement OTA » : binaire servi en entier puis 2 réveils sans contact → P1 ;
 * contact revenu après le téléchargement → rien ; téléchargement partiel → rien.
 */
final class OtaSilenceAlertServiceTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        putenv('LOG_FILE_PATH=php://memory');
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    /** @param list<int> $contacts */
    private function evidence(array $contacts): ContactEvidenceSource
    {
        return new class ($contacts) implements ContactEvidenceSource {
            public function __construct(private array $contacts)
            {
            }

            public function recentContacts(string $family): array
            {
                return $this->contacts;
            }

            public function lastAuthenticatedContact(string $family): ?int
            {
                return $this->contacts === [] ? null : max($this->contacts);
            }

            public function expectedPeriodSeconds(string $family): ?int
            {
                return 2000;
            }
        };
    }

    /** @return list<int> */
    private static function bursts(int $lastStart, int $n = 4): array
    {
        $out = [];
        for ($k = 0; $k < $n; $k++) {
            for ($i = 0; $i < 6; $i++) {
                $out[] = $lastStart - $k * 2000 + 30 * $i;
            }
        }

        return $out;
    }

    public function testCompleteDownloadThenSilenceTriggersAlert(): void
    {
        $now = time();
        $download = $now - 5000; // 2 réveils (2000 s) + marge écoulés
        $repo = new OtaEventRepository($this->pdo);
        $repo->record(['ip' => '105.155.251.0', 'path' => 'esp32-wroom/firmware.bin', 'channel' => 'esp32-wroom', 'kind' => 'bin', 'board' => 'esp32-wroom-a1', 'http_status' => 200, 'bytes_sent' => 1575872, 'file_size' => 1575872, 'complete' => true, 'occurred_at' => date('Y-m-d H:i:s', $download)]);

        $notifier = $this->createMock(NotificationService::class);
        $notifier->expects($this->once())->method('sendAlert')
            ->with(Severity::Critical, $this->anything(), 'FFP3', 'Module muet après téléchargement OTA', $this->stringContains('1575872 octets'), $this->stringStartsWith('ffp3:ota-silent:'))
            ->willReturn(true);

        $service = new OtaSilenceAlertService($repo, $this->evidence(self::bursts($download - 120)), $notifier, $this->createMock(LogService::class));
        self::assertSame(1, $service->run(['ffp3'], $now));
    }

    public function testContactAfterDownloadMeansNoAlert(): void
    {
        $now = time();
        $download = $now - 5000;
        $repo = new OtaEventRepository($this->pdo);
        $repo->record(['path' => 'esp32-wroom/firmware.bin', 'channel' => 'esp32-wroom', 'kind' => 'bin', 'http_status' => 200, 'bytes_sent' => 10, 'file_size' => 10, 'complete' => true, 'occurred_at' => date('Y-m-d H:i:s', $download)]);
        $notifier = $this->createMock(NotificationService::class);
        $notifier->expects($this->never())->method('sendAlert');

        $service = new OtaSilenceAlertService($repo, $this->evidence(array_merge(self::bursts($download - 120), [$download + 400])), $notifier, $this->createMock(LogService::class));
        $verdict = $service->evaluate('ffp3', 2, $now);
        self::assertNotNull($verdict);
        self::assertFalse($verdict['alert']);
        self::assertSame(0, $service->run(['ffp3'], $now));
    }

    public function testPartialDownloadOrNoDownloadIsIgnored(): void
    {
        $now = time();
        $repo = new OtaEventRepository($this->pdo);
        $repo->record(['path' => 'esp32-wroom/firmware.bin', 'channel' => 'esp32-wroom', 'kind' => 'bin', 'http_status' => 206, 'bytes_sent' => 100, 'file_size' => 1000, 'complete' => false, 'occurred_at' => date('Y-m-d H:i:s', $now - 5000)]);
        $service = new OtaSilenceAlertService($repo, $this->evidence(self::bursts($now - 6000)), $this->createMock(NotificationService::class), $this->createMock(LogService::class));

        self::assertNull($service->evaluate('ffp3', 2, $now), 'aucun téléchargement COMPLET : rien à juger');
        self::assertNull($service->evaluate('pgl', 2, $now), 'famille sans canal OTA');
    }
}

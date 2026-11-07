<?php

declare(strict_types=1);

namespace Tests\Repository;

use App\Repository\DeviceContactRepository;
use App\Service\ContactRecorder;
use App\Service\LogService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Journal des contacts : création à la volée, décalage d'horloge, dernier contact authentifié
 * (le CRC seul du heartbeat FFP3 legacy n'en est pas un), fenêtre, offsets récents.
 */
final class DeviceContactRepositoryTest extends TestCase
{
    private PDO $pdo;
    private DeviceContactRepository $repo;

    protected function setUp(): void
    {
        putenv('LOG_FILE_PATH=php://memory');
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->repo = new DeviceContactRepository($this->pdo);
    }

    public function testRecorderCreatesTableAndComputesClockOffset(): void
    {
        $recorder = new ContactRecorder($this->repo, $this->createMock(LogService::class));
        $now = time();
        // Cas du 06/11/2026 : firmware en avance de 2 045 s, signature rejetée.
        $recorder->record([
            'family' => 'ffp3', 'env' => 'prod', 'kind' => 'post', 'accepted' => false, 'http_status' => 401,
            'auth_mode' => 'none', 'reject_reason' => 'signature_invalid', 'ts_received' => (string) ($now + 2045),
            'firmware_version' => '15.31', 'post_id' => 'esp32-wroom-1793981517-1717', 'ip' => '105.155.251.0',
        ]);
        $recorder->record([
            'family' => 'ffp3', 'env' => 'prod', 'kind' => 'post', 'accepted' => true, 'http_status' => 200,
            'auth_mode' => 'api_key', 'ts_received' => null, 'firmware_version' => '15.31', 'ip' => '105.155.251.0',
        ]);
        $recorder->record([
            'family' => 'ffp3', 'env' => 'prod', 'kind' => 'heartbeat', 'accepted' => true, 'http_status' => 200,
            'auth_mode' => 'crc_only', 'ts_received' => null, 'ip' => '105.155.251.0',
        ]);

        $rows = $this->repo->between('ffp3', 'prod', '2000-01-01 00:00:00', '2100-01-01 00:00:00');
        self::assertCount(3, $rows);
        self::assertSame(0, (int) $rows[0]['accepted']);
        self::assertSame('signature_invalid', $rows[0]['reject_reason']);
        self::assertEqualsWithDelta(2045, (int) $rows[0]['clock_offset_s'], 2);
        self::assertNull($rows[1]['clock_offset_s']);

        $offsets = $this->repo->recentClockOffsets('ffp3', 'prod');
        self::assertCount(1, $offsets);
        self::assertEqualsWithDelta(2045, $offsets[0]['clock_offset_s'], 2);
    }

    public function testLastAuthenticatedContactIgnoresCrcOnlyHeartbeat(): void
    {
        $this->repo->ensureSchema();
        $this->repo->record(['family' => 'ffp3', 'env' => 'prod', 'kind' => 'post', 'accepted' => true, 'http_status' => 200, 'auth_mode' => 'x_sig_body', 'received_at' => '2026-11-06 16:04:43']);
        $this->repo->record(['family' => 'ffp3', 'env' => 'prod', 'kind' => 'post', 'accepted' => false, 'http_status' => 401, 'auth_mode' => 'none', 'received_at' => '2026-11-06 16:38:50']);
        $this->repo->record(['family' => 'ffp3', 'env' => 'prod', 'kind' => 'heartbeat', 'accepted' => true, 'http_status' => 200, 'auth_mode' => 'crc_only', 'received_at' => '2026-11-06 16:40:27']);

        self::assertSame(strtotime('2026-11-06 16:04:43'), $this->repo->lastAcceptedContact('ffp3', true));
        self::assertSame(strtotime('2026-11-06 16:40:27'), $this->repo->lastAcceptedContact('ffp3', false));
        self::assertNull($this->repo->lastAcceptedContact('n3pp', true));
    }

    public function testMissingTableIsDetected(): void
    {
        self::assertTrue(DeviceContactRepository::isMissingTable(new \PDOException("SQLSTATE[HY000]: General error: 1 no such table: device_contacts")));
        self::assertTrue(DeviceContactRepository::isMissingTable(new \PDOException("SQLSTATE[42S02]: Base table or view not found: 1146 Table 'oliviera_iot.device_contacts' doesn't exist")));
        self::assertFalse(DeviceContactRepository::isMissingTable(new \PDOException('SQLSTATE[23000]: Integrity constraint violation')));
    }
}

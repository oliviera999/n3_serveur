<?php

declare(strict_types=1);

namespace Tests\Mcp;

use App\Mcp\McpContext;
use App\Mcp\Support\ActiveAlertsReader;
use App\Mcp\Support\GalleryReader;
use App\Mcp\Timeline\ContactTimelineBuilder;
use App\Mcp\Tools\ContactTimelineTool;
use App\Repository\DeviceContactRepository;
use App\Repository\DeviceEventRepository;
use App\Repository\McpReadRepository;
use App\Repository\OtaEventRepository;
use App\Repository\OtaReleaseRepository;
use App\Repository\OutputMonitorRepository;
use App\Service\OfflineThresholdResolver;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\McpSqliteFixture;

/**
 * `iot_contact_timeline` sur SQLite : reconstitution du 06/11/2026 (rafales de POST, rejets de
 * signature avec horloge en avance, heartbeats, publication 15.33, binaire téléchargé en entier,
 * puis silence) → frise fusionnée, dernier contact, réveils manqués, verdict OTA.
 */
final class ContactTimelineToolTest extends TestCase
{
    private PDO $pdo;
    private string $tmp;

    protected function setUp(): void
    {
        \App\Config\Env::load();
        putenv('ENV=prod');
        $_ENV['ENV'] = 'prod';
        $this->pdo = McpSqliteFixture::pdo();
        McpSqliteFixture::seedAllOutputs($this->pdo);
        $this->pdo->exec("UPDATE ffp3Outputs SET state = '2000' WHERE gpio = 116");
        $this->pdo->exec("INSERT INTO ffp3Outputs (board, gpio, name, state, requestTime, lastModifiedBy) VALUES ('1', 116, 'FreqWakeUp', '2000', '2026-11-06 00:00:00', 'esp32')");
        $this->tmp = sys_get_temp_dir() . '/mcp-timeline-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/var/cache', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tmp));
        \App\Config\TableConfig::resetRequestEnvironment();
    }

    private function seedIncident(): void
    {
        $t = static fn (string $hms): string => '2026-11-06 ' . $hms;
        $post = $this->pdo->prepare("INSERT INTO ffp3Data (sensor, version, TempAir, reading_time) VALUES ('ffp3', '15.31', 18.9, ?)");
        foreach (['15:19:53', '15:23:00', '15:23:58', '15:24:56', '15:25:53', '15:59:30', '16:00:26', '16:00:58', '16:01:31', '16:02:04', '16:04:43', '16:38:20', '16:39:18'] as $hms) {
            $post->execute([$t($hms)]);
        }
        $hb = $this->pdo->prepare('INSERT INTO ffp3Heartbeat (uptime, freeHeap, minHeap, reboots, `timestamp`) VALUES (?, 54724, 25100, 105, ?)');
        $hb->execute([501399, $t('15:21:05')]);
        $hb->execute([506249, $t('16:40:27')]);

        $contacts = new DeviceContactRepository($this->pdo);
        foreach ([['16:37:52', 0, 401, 'none', 'signature_invalid', 2045], ['16:38:20', 1, 200, 'api_key', null, null], ['16:38:50', 0, 401, 'none', 'signature_invalid', 2045], ['16:39:18', 1, 200, 'api_key', null, null]] as [$hms, $ok, $status, $auth, $reason, $offset]) {
            $contacts->record(['family' => 'ffp3', 'env' => 'prod', 'kind' => 'post', 'accepted' => (bool) $ok, 'http_status' => $status, 'auth_mode' => $auth, 'reject_reason' => $reason, 'clock_offset_s' => $offset, 'firmware_version' => '15.31', 'received_at' => $t($hms)]);
        }
        $contacts->record(['family' => 'ffp3', 'env' => 'prod', 'kind' => 'heartbeat', 'accepted' => false, 'http_status' => 401, 'auth_mode' => 'none', 'reject_reason' => 'signature_invalid', 'clock_offset_s' => 2045, 'received_at' => $t('16:39:58')]);
        $contacts->record(['family' => 'ffp3', 'env' => 'prod', 'kind' => 'heartbeat', 'accepted' => true, 'http_status' => 200, 'auth_mode' => 'crc_only', 'received_at' => $t('16:40:27')]);

        (new OtaReleaseRepository($this->pdo))->recordIfNew(['target' => 'ffp5', 'channel' => 'prod', 'board' => 'esp32-wroom', 'version' => '15.33', 'hash' => '552a56f210729a6ff72234c2c16cccae', 'size' => 1575872, 'published_at' => $t('16:02:00'), 'author' => 'oliviera999']);
        $ota = new OtaEventRepository($this->pdo);
        $ota->record(['ip' => '105.155.251.0', 'path' => 'metadata.json', 'channel' => 'root', 'kind' => 'metadata', 'http_status' => 200, 'bytes_sent' => 1129, 'file_size' => 1129, 'complete' => true, 'occurred_at' => $t('16:40:31')]);
        $ota->record(['ip' => '105.155.251.0', 'path' => 'esp32-wroom/firmware.bin', 'channel' => 'esp32-wroom', 'kind' => 'bin', 'http_status' => 200, 'bytes_sent' => 1575872, 'file_size' => 1575872, 'duration_ms' => 42000, 'complete' => true, 'occurred_at' => $t('16:41:20')]);
        (new DeviceEventRepository($this->pdo))->record(['family' => 'ffp3', 'env' => 'prod', 'board' => 'esp32-wroom', 'firmware_version' => '15.31', 'source' => 'ota_report', 'seq' => 1, 'event' => 'start', 'detail' => '15.31->15.33', 'received_at' => $t('16:40:35')]);
        $this->pdo->exec("INSERT INTO notification_log (alert_key, severity, category, recipient, subject, sent_at) VALUES ('ffp3:offline', 'critical', 'availability', 'x@example.org', '[FFP3][P1] Système hors ligne', '" . $t('18:35:04') . "')");
    }

    private function tool(): ContactTimelineTool
    {
        $read = new McpReadRepository($this->pdo);
        $builder = new ContactTimelineBuilder(
            $read,
            new DeviceContactRepository($this->pdo),
            new OtaEventRepository($this->pdo),
            new OtaReleaseRepository($this->pdo),
            new DeviceEventRepository($this->pdo),
            new GalleryReader($this->tmp),
            new ActiveAlertsReader($this->tmp . '/var/cache', $this->tmp . '/flag'),
            new OfflineThresholdResolver(new OutputMonitorRepository($this->pdo))
        );

        return new ContactTimelineTool(McpSqliteFixture::config(), $builder);
    }

    public function testIncidentTimelineAndVerdict(): void
    {
        $this->seedIncident();
        $result = $this->tool()->call(['family' => 'ffp3', 'from' => '2026-11-06 15:00', 'to' => '2026-11-06 22:00', 'env' => 'prod'], new McpContext(1, 'l', 'reader', ['iot.read'], 'c'));
        $data = $result->structured();

        self::assertSame('prod', $data['env']);
        self::assertTrue($data['is_production']);
        self::assertSame('2026-11-06 16:40:27', $data['last_contact']);
        self::assertSame('2026-11-06 16:39:18', $data['last_authenticated_contact'], 'le heartbeat CRC seul n\'est pas authentifié');
        self::assertSame('15.31', $data['firmware_version_seen']);
        self::assertSame(3, $data['counts']['post_bursts']);
        self::assertSame(3, $data['counts']['rejected']);
        self::assertSame(2, $data['counts']['ota_server_events']);
        self::assertSame(1, $data['counts']['ota_releases']);

        $types = array_count_values(array_column($data['timeline'], 'type'));
        self::assertSame(3, $types['post_burst']);
        self::assertSame(3, $types['contact_rejected']);
        self::assertSame(2, $types['heartbeat']);
        self::assertSame(2, $types['ota_server']);
        self::assertSame(1, $types['ota_release']);
        self::assertSame(1, $types['firmware_ota_report']);
        self::assertSame(1, $types['notification']);
        $labels = array_column($data['timeline'], 'label');
        self::assertTrue((bool) preg_grep('/REJETÉE post \(signature_invalid\), horloge firmware \+2045 s/', $labels));
        self::assertTrue((bool) preg_grep('/binaire esp32-wroom\/firmware.bin → HTTP 200, 1575872\/1575872 octets, COMPLET/', $labels));
        self::assertTrue((bool) preg_grep('/Publication OTA ffp5 prod\/esp32-wroom version 15.33/', $labels));
        // Ordre chronologique.
        $ats = array_column($data['timeline'], 'at');
        $sorted = $ats;
        sort($sorted);
        self::assertSame($sorted, $ats);

        self::assertTrue($data['expected_wake']['offline']);
        self::assertGreaterThanOrEqual(2, $data['expected_wake']['missed_wakes']);
        self::assertSame(2000, $data['expected_wake']['configured_period_seconds'], 'FreqWakeUp lu en BDD (GPIO 116)');
        self::assertSame('ota', $data['classification']['causes'][0]['cause']);
        self::assertStringContainsString('Mise à jour OTA', $data['classification']['verdict']);
        self::assertNotEmpty($data['classification']['unverifiable']);
    }

    public function testEmptyWindowGivesNeutralResult(): void
    {
        $result = $this->tool()->call(['family' => 'n3pp', 'from' => '2026-11-06 00:00', 'to' => '2026-11-06 01:00'], new McpContext(1, 'l', 'reader', ['iot.read'], 'c'));
        $data = $result->structured();

        self::assertNull($data['last_contact']);
        self::assertSame([], $data['timeline']);
        self::assertFalse($data['expected_wake']['offline']);
        self::assertSame('Module en ligne : aucune perte de contact à expliquer.', $data['classification']['verdict']);
    }

    public function testSchemaIsStrict(): void
    {
        $schema = $this->tool()->inputSchema();
        self::assertSame('object', $schema['type']);
        self::assertFalse($schema['additionalProperties']);
        self::assertSame(['family'], $schema['required']);
        self::assertTrue($this->tool()->isReadOnly());
    }
}

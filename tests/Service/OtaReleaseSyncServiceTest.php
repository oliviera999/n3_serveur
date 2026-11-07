<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Repository\OtaReleaseRepository;
use App\Service\LogService;
use App\Service\OtaReleaseSyncService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Détection des publications OTA à partir des manifestes du dossier ota/ : topologie ffp5cs
 * (manifeste racine multi-canaux / multi-cartes) et n3ota (un manifeste par cible, cam multi-cartes),
 * idempotence (un passage CRON suivant n'ajoute rien), nouvelle version détectée.
 */
final class OtaReleaseSyncServiceTest extends TestCase
{
    private string $dir;
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ota_sync_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/n3pp', 0777, true);
        mkdir($this->dir . '/cam', 0777, true);
        file_put_contents($this->dir . '/metadata.json', json_encode([
            'version' => '15.33',
            'channels' => [
                'prod' => [
                    'esp32-wroom' => ['version' => '15.33', 'md5' => '552a56f210729a6ff72234c2c16cccae', 'size' => 1575872, 'bin_url' => 'https://iot.example/ota/esp32-wroom/firmware.bin'],
                    'esp32-s3' => ['version' => '15.33', 'md5' => 'adbbac62ab91b6d02b068f8654f7f4cc', 'size' => 1457328, 'bin_url' => 'https://iot.example/ota/esp32-s3/firmware.bin'],
                ],
                'test' => [
                    'esp32-wroom' => ['version' => '15.33', 'md5' => 'bc8631038afb45d88286e6b63d509c46', 'size' => 1684352, 'bin_url' => 'https://iot.example/ota/esp32-wroom-beta/firmware.bin'],
                ],
            ],
        ]));
        file_put_contents($this->dir . '/n3pp/metadata.json', json_encode(['version' => '4.55', 'sha256' => str_repeat('b', 64), 'url' => 'http://iot.example/ota/n3pp/firmware.bin']));
        file_put_contents($this->dir . '/n3pp/firmware.bin', str_repeat('x', 1234));
        file_put_contents($this->dir . '/cam/metadata.json', json_encode([
            'msp1' => ['version' => '2.82', 'sha256' => str_repeat('c', 64), 'url' => 'https://iot.example/ota/cam/msp1/firmware.bin'],
            'ffp3' => ['version' => '2.82', 'sha256' => str_repeat('d', 64), 'url' => 'https://iot.example/ota/cam/ffp3/firmware.bin'],
        ]));

        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function service(): OtaReleaseSyncService
    {
        return new OtaReleaseSyncService(new OtaReleaseRepository($this->pdo), $this->createMock(LogService::class), $this->dir, false);
    }

    public function testDiscoversAllManifestEntries(): void
    {
        $releases = $this->service()->discover();
        $keys = array_map(static fn (array $r): string => "{$r['target']}/{$r['channel']}/{$r['board']}/{$r['version']}", $releases);

        self::assertSame([
            'ffp5/prod/esp32-wroom/15.33',
            'ffp5/prod/esp32-s3/15.33',
            'ffp5/test/esp32-wroom/15.33',
            'n3pp/n3pp/-/4.55',
            'cam/cam/msp1/2.82',
            'cam/cam/ffp3/2.82',
        ], $keys);
        self::assertSame('552a56f210729a6ff72234c2c16cccae', $releases[0]['hash']);
        self::assertSame(1575872, $releases[0]['size']);
        self::assertSame(1234, $releases[3]['size'], 'taille lue sur le binaire quand le manifeste ne la donne pas');
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $releases[0]['published_at']);
        self::assertNull($releases[0]['author'], 'git désactivé dans ce test');
    }

    public function testSyncIsIdempotentAndDetectsNewVersion(): void
    {
        $service = $this->service();
        // La table n'existe pas encore : créée à la première écriture (additif).
        self::assertCount(6, $service->sync('cron'));
        self::assertCount(0, $service->sync('cron'), 'second passage : rien de nouveau');

        // Publication d'une 15.34 sur le canal prod WROOM uniquement.
        $doc = json_decode((string) file_get_contents($this->dir . '/metadata.json'), true);
        $doc['channels']['prod']['esp32-wroom'] = ['version' => '15.34', 'md5' => str_repeat('e', 32), 'size' => 1580000, 'bin_url' => 'https://iot.example/ota/esp32-wroom/firmware.bin'];
        file_put_contents($this->dir . '/metadata.json', json_encode($doc));

        $added = $service->sync('cron');
        self::assertCount(1, $added);
        self::assertSame('15.34', $added[0]['version']);
        self::assertSame('esp32-wroom', $added[0]['board']);

        $repo = new OtaReleaseRepository($this->pdo);
        $latest = $repo->latest('ffp5', 'prod', 'esp32-wroom');
        self::assertNotNull($latest);
        self::assertSame('15.34', $latest['version']);
        self::assertCount(7, $repo->between(['ffp5', 'n3pp', 'cam'], '2000-01-01 00:00:00', '2100-01-01 00:00:00'));
    }
}

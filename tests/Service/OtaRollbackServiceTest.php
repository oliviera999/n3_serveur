<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\OtaRollbackService;
use PHPUnit\Framework\TestCase;

/**
 * Tests du système de rollback OTA serveur : snapshot, liste, restauration
 * (avec auto-backup), assainissement des étiquettes, topologie caméra (bins en
 * sous-dossiers), et rejet des cibles inconnues.
 */
class OtaRollbackServiceTest extends TestCase
{
    private string $otaRoot;

    protected function setUp(): void
    {
        $this->otaRoot = sys_get_temp_dir() . '/ota_rollback_' . bin2hex(random_bytes(6));
        mkdir($this->otaRoot . '/n3pp', 0775, true);
        file_put_contents($this->otaRoot . '/n3pp/metadata.json', '{"version":"4.55","sha256":"a","signature":"s"}');
        file_put_contents($this->otaRoot . '/n3pp/firmware.bin', 'FIRMWARE-4.55');
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->otaRoot);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    public function testTargetsListed(): void
    {
        $service = new OtaRollbackService($this->otaRoot);
        $this->assertContains('n3pp', $service->targets());
        $this->assertContains('cam', $service->targets());
    }

    public function testCurrentLabelReadsVersion(): void
    {
        $service = new OtaRollbackService($this->otaRoot);
        $this->assertSame('4.55', $service->currentLabel('n3pp'));
    }

    public function testSnapshotThenListThenRestore(): void
    {
        $service = new OtaRollbackService($this->otaRoot);

        $label = $service->snapshot('n3pp');
        $this->assertSame('4.55', $label);
        $this->assertSame(['4.55'], $service->listSnapshots('n3pp'));
        $this->assertFileExists($this->otaRoot . '/n3pp/history/4.55/metadata.json');
        $this->assertFileExists($this->otaRoot . '/n3pp/history/4.55/firmware.bin');

        // Nouvelle publication (mauvaise) écrase l'état servi.
        file_put_contents($this->otaRoot . '/n3pp/metadata.json', '{"version":"4.63"}');
        file_put_contents($this->otaRoot . '/n3pp/firmware.bin', 'FIRMWARE-4.63-BAD');
        $this->assertSame('4.63', $service->currentLabel('n3pp'));

        // Rollback vers la version saine.
        $restored = $service->restore('n3pp', '4.55');
        $this->assertContains('firmware.bin', $restored);
        $this->assertSame('FIRMWARE-4.55', file_get_contents($this->otaRoot . '/n3pp/firmware.bin'));
        $this->assertSame('4.55', $service->currentLabel('n3pp'));

        // L'état « mauvais » 4.63 a été auto-sauvegardé avant écrasement.
        $this->assertContains('4.63', $service->listSnapshots('n3pp'));
    }

    public function testDryRunDoesNotWrite(): void
    {
        $service = new OtaRollbackService($this->otaRoot);
        $service->snapshot('n3pp');
        file_put_contents($this->otaRoot . '/n3pp/firmware.bin', 'FIRMWARE-4.63-BAD');

        $files = $service->restore('n3pp', '4.55', true);
        $this->assertContains('firmware.bin', $files);
        // Rien n'a été écrit : le binaire « mauvais » est toujours en place.
        $this->assertSame('FIRMWARE-4.63-BAD', file_get_contents($this->otaRoot . '/n3pp/firmware.bin'));
    }

    public function testCamTopologyWithNestedBins(): void
    {
        mkdir($this->otaRoot . '/cam/msp1', 0775, true);
        mkdir($this->otaRoot . '/cam/n3pp', 0775, true);
        file_put_contents($this->otaRoot . '/cam/metadata.json', '{"msp1":{"version":"2.64"}}');
        file_put_contents($this->otaRoot . '/cam/msp1/firmware.bin', 'CAM-MSP1');
        file_put_contents($this->otaRoot . '/cam/n3pp/firmware.bin', 'CAM-N3PP');

        $service = new OtaRollbackService($this->otaRoot);
        $label = $service->snapshot('cam', 'cam-2.64');
        $this->assertSame('cam-2.64', $label);
        $this->assertFileExists($this->otaRoot . '/cam/history/cam-2.64/msp1/firmware.bin');
        $this->assertFileExists($this->otaRoot . '/cam/history/cam-2.64/n3pp/firmware.bin');

        file_put_contents($this->otaRoot . '/cam/msp1/firmware.bin', 'CAM-MSP1-BAD');
        $service->restore('cam', 'cam-2.64');
        $this->assertSame('CAM-MSP1', file_get_contents($this->otaRoot . '/cam/msp1/firmware.bin'));
    }

    public function testUnknownTargetThrows(): void
    {
        $service = new OtaRollbackService($this->otaRoot);
        $this->expectException(\InvalidArgumentException::class);
        $service->currentLabel('does-not-exist');
    }

    public function testMissingSnapshotThrows(): void
    {
        $service = new OtaRollbackService($this->otaRoot);
        $this->expectException(\RuntimeException::class);
        $service->restore('n3pp', 'nope');
    }

    public function testLabelTraversalIsSanitized(): void
    {
        $service = new OtaRollbackService($this->otaRoot);
        $label = $service->snapshot('n3pp', '../../evil');
        // Les séparateurs et « .. » sont neutralisés : reste sous history/.
        $this->assertStringNotContainsString('..', $label);
        $this->assertDirectoryExists($this->otaRoot . '/n3pp/history/' . $label);
        $this->assertDirectoryDoesNotExist($this->otaRoot . '/evil');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Mcp;

use App\Mcp\Timeline\ContactLossClassifier;
use PHPUnit\Framework\TestCase;

/**
 * Classification de la cause d'une perte de contact : scénarios archétypaux, dont le 06/11/2026
 * (OTA publiée 38 min avant, caméra du site toujours active, rejets de signature dans la rafale,
 * puis plus aucune requête).
 */
final class ContactLossClassifierTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function base(array $overrides = []): array
    {
        return array_replace_recursive([
            'now' => 1762450000,
            'last_contact' => 1762450000 - 3 * 3600,
            'silence_seconds' => 3 * 3600,
            'period' => 2000,
            'missed_wakes' => 5,
            'offline' => true,
            'requests_after_loss' => 0,
            'rejected_after_loss' => 0,
            'last_burst' => ['requests' => 4, 'rejected' => 0, 'signature_invalid' => 0, 'max_abs_offset' => 0],
            'ota' => ['events_journaled' => true, 'release_before_loss_seconds' => null, 'release_version' => null, 'metadata_fetch_before_loss_seconds' => null, 'bin_download_before_loss_seconds' => null, 'bin_complete' => false, 'firmware_events' => []],
            'heartbeat' => ['last_reset_reason' => null, 'crash_reasons' => [], 'reboots_delta' => null, 'safe_mode' => false, 'last_uptime' => null, 'last_ota_state' => null],
            'camera' => ['had_uploads_before' => true, 'uploads_after_loss' => 12],
            'server' => ['errors_5xx_after_loss' => 0, 'cron_late' => false, 'other_devices_contacting' => true],
            'wifi' => ['fail_events' => 0, 'last_rssi' => -62],
            'firmware' => ['seen_version' => '15.31', 'published_version' => null],
        ], $overrides);
    }

    /** @return array<string, float> */
    private static function probabilities(array $result): array
    {
        $out = [];
        foreach ($result['causes'] as $c) {
            $out[$c['cause']] = $c['probability'];
        }

        return $out;
    }

    public function testIncident20261106PointsToOta(): void
    {
        $result = (new ContactLossClassifier())->classify(self::base([
            'last_burst' => ['requests' => 6, 'rejected' => 3, 'signature_invalid' => 3, 'max_abs_offset' => 2045],
            'ota' => ['release_before_loss_seconds' => 38 * 60, 'release_version' => '15.33', 'metadata_fetch_before_loss_seconds' => 90, 'bin_download_before_loss_seconds' => 60, 'bin_complete' => true],
            'heartbeat' => ['last_uptime' => 506249, 'reboots_delta' => 0],
        ]));

        self::assertTrue($result['offline']);
        self::assertSame('ota', $result['causes'][0]['cause']);
        self::assertStringContainsString('Mise à jour OTA', $result['verdict']);
        $p = self::probabilities($result);
        self::assertGreaterThan(0.5, $p['ota']);
        self::assertLessThan($p['ota'], $p['clock_signature']);
        self::assertLessThan(0.1, $p['wifi_network'], 'la caméra du site continue : réseau sain');
        self::assertContains('la caméra du même site a continué d\'envoyer (12 photo(s) après la perte) : réseau du site sain', $result['causes'][array_search('wifi_network', array_column($result['causes'], 'cause'), true)]['against']);
        self::assertNotEmpty($result['unverifiable']);
    }

    public function testClockSignatureWhenRequestsKeepArrivingButAreRejected(): void
    {
        $result = (new ContactLossClassifier())->classify(self::base([
            'requests_after_loss' => 9,
            'rejected_after_loss' => 9,
            'last_burst' => ['requests' => 6, 'rejected' => 6, 'signature_invalid' => 6, 'max_abs_offset' => 4090],
        ]));

        self::assertSame('clock_signature', $result['causes'][0]['cause']);
        $p = self::probabilities($result);
        self::assertLessThan(0.05, $p['power'], 'un module qui émet est alimenté');
    }

    public function testCrashLoopFromResetReasons(): void
    {
        $result = (new ContactLossClassifier())->classify(self::base([
            'heartbeat' => ['last_reset_reason' => 'TASK_WDT', 'crash_reasons' => ['PANIC', 'TASK_WDT', 'TASK_WDT'], 'reboots_delta' => 4, 'safe_mode' => true],
        ]));

        self::assertSame('crash_reboot_loop', $result['causes'][0]['cause']);
    }

    public function testSiteWideSilenceSuggestsNetworkOrPower(): void
    {
        $result = (new ContactLossClassifier())->classify(self::base([
            'camera' => ['had_uploads_before' => true, 'uploads_after_loss' => 0],
            'server' => ['other_devices_contacting' => false],
        ]));

        self::assertContains($result['causes'][0]['cause'], ['wifi_network', 'power']);
        $p = self::probabilities($result);
        self::assertGreaterThan($p['ota'], $p['wifi_network']);
    }

    public function testServerErrorsAfterLoss(): void
    {
        $result = (new ContactLossClassifier())->classify(self::base([
            'requests_after_loss' => 5,
            'rejected_after_loss' => 5,
            'server' => ['errors_5xx_after_loss' => 5, 'cron_late' => true, 'other_devices_contacting' => true],
        ]));

        self::assertContains($result['causes'][0]['cause'], ['server', 'clock_signature']);
        $p = self::probabilities($result);
        self::assertGreaterThan(0.25, $p['server']);
    }

    public function testOnlineDeviceHasNeutralVerdict(): void
    {
        $result = (new ContactLossClassifier())->classify(self::base(['offline' => false, 'missed_wakes' => 0, 'silence_seconds' => 120]));

        self::assertFalse($result['offline']);
        self::assertSame('Module en ligne : aucune perte de contact à expliquer.', $result['verdict']);
        self::assertEqualsWithDelta(1.0, array_sum(self::probabilities($result)), 0.05);
    }

    public function testEveryCauseIsReportedWithLabel(): void
    {
        $result = (new ContactLossClassifier())->classify(self::base());
        self::assertSame(ContactLossClassifier::CAUSES, array_values(array_unique(array_merge(ContactLossClassifier::CAUSES, array_column($result['causes'], 'cause')))));
        foreach ($result['causes'] as $c) {
            self::assertArrayHasKey($c['cause'], ContactLossClassifier::LABELS);
            self::assertIsArray($c['for']);
            self::assertIsArray($c['against']);
        }
    }
}

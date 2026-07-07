<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Config\OperationalSettingsCatalog;
use App\Repository\ServerSettingsRepository;
use App\Service\OperationalSettingsService;
use PHPUnit\Framework\TestCase;

class OperationalSettingsServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['HMAC_STRICT_MODE'], $_ENV['HMAC_NONCE_REQUIRED'], $_ENV['NOTIF_MODE'], $_ENV['AQUA_LOW_LEVEL_THRESHOLD']);
    }

    public function testIntFallsBackToEnv(): void
    {
        $_ENV['AQUA_LOW_LEVEL_THRESHOLD'] = '200';
        $settings = $this->createMock(ServerSettingsRepository::class);
        $settings->method('hasKey')->willReturn(false);

        $ops = new OperationalSettingsService($settings);
        $this->assertSame(200, $ops->int('AQUA_LOW_LEVEL_THRESHOLD', 180));
    }

    public function testDatabaseOverrideForBool(): void
    {
        $_ENV['OTA_REQUIRE_AUTH'] = 'false';
        $dbKey = OperationalSettingsCatalog::dbKey('OTA_REQUIRE_AUTH');
        $settings = $this->createMock(ServerSettingsRepository::class);
        $settings->method('hasKey')->willReturnCallback(static fn (string $k): bool => $k === $dbKey);
        $settings->method('getBool')->willReturn(true);

        $ops = new OperationalSettingsService($settings);
        $this->assertTrue($ops->bool('OTA_REQUIRE_AUTH', false));
    }

    public function testOptionalFloatDisabledViaOffMarker(): void
    {
        $dbKey = OperationalSettingsCatalog::dbKey('MSP_FROST_ALERT_THRESHOLD_C');
        $settings = $this->createMock(ServerSettingsRepository::class);
        $settings->method('hasKey')->willReturn(true);
        $settings->method('getRaw')->willReturn('__OFF__');

        $ops = new OperationalSettingsService($settings);
        $this->assertNull($ops->optionalFloat('MSP_FROST_ALERT_THRESHOLD_C'));
    }

    public function testExportForUiContainsAllCatalogEntries(): void
    {
        $settings = $this->createMock(ServerSettingsRepository::class);
        $settings->method('hasKey')->willReturn(false);

        $ops = new OperationalSettingsService($settings);
        $export = $ops->exportForUi();
        $this->assertCount(count(OperationalSettingsCatalog::all()), $export['settings']);
    }

    public function testSetAndReset(): void
    {
        $settings = $this->createMock(ServerSettingsRepository::class);
        $settings->expects($this->once())->method('setRaw')->with(
            OperationalSettingsCatalog::dbKey('NOTIF_MODE'),
            'none',
            'admin'
        );
        $settings->expects($this->once())->method('deleteKey')->with(
            OperationalSettingsCatalog::dbKey('NOTIF_MODE')
        );

        $ops = new OperationalSettingsService($settings);
        $ops->set('NOTIF_MODE', 'none', 'admin');
        $ops->reset('NOTIF_MODE');
    }
}

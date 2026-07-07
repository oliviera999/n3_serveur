<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Repository\ServerSettingsRepository;
use App\Service\HmacPolicyService;
use PHPUnit\Framework\TestCase;

class HmacPolicyServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['HMAC_STRICT_MODE'], $_ENV['HMAC_NONCE_REQUIRED']);
    }

    public function testStrictModeFallsBackToEnvWhenNoDatabaseOverride(): void
    {
        $_ENV['HMAC_STRICT_MODE'] = 'true';
        $_ENV['HMAC_NONCE_REQUIRED'] = 'false';

        $settings = $this->createMock(ServerSettingsRepository::class);
        $settings->method('getBool')
            ->willReturnCallback(static function (string $key, bool $default): bool {
                return $default;
            });
        $settings->method('hasKey')->willReturn(false);

        $policy = new HmacPolicyService($settings);

        $this->assertTrue($policy->isStrictMode());
        $this->assertFalse($policy->isNonceRequired());
        $status = $policy->getStatus();
        $this->assertSame('env', $status['strict_source']);
        $this->assertTrue($status['env_strict']);
    }

    public function testDatabaseOverrideTakesPrecedenceOverEnv(): void
    {
        $_ENV['HMAC_STRICT_MODE'] = 'false';

        $settings = $this->createMock(ServerSettingsRepository::class);
        $settings->method('getBool')
            ->willReturnCallback(static function (string $key, bool $default): bool {
                if ($key === ServerSettingsRepository::KEY_HMAC_STRICT_MODE) {
                    return true;
                }

                return $default;
            });
        $settings->method('hasKey')
            ->willReturnCallback(static fn (string $key): bool => $key === ServerSettingsRepository::KEY_HMAC_STRICT_MODE);

        $policy = new HmacPolicyService($settings);

        $this->assertTrue($policy->isStrictMode());
        $this->assertSame('database', $policy->getStatus()['strict_source']);
    }

    public function testResetStrictModeDeletesDatabaseKey(): void
    {
        $settings = $this->createMock(ServerSettingsRepository::class);
        $settings->expects($this->once())
            ->method('deleteKey')
            ->with(ServerSettingsRepository::KEY_HMAC_STRICT_MODE);

        $policy = new HmacPolicyService($settings);
        $policy->resetStrictMode();
    }
}

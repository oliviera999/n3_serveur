<?php

declare(strict_types=1);

namespace Tests\Notification;

use App\Notification\NotificationCategory;
use App\Notification\NotificationFamily;
use App\Notification\NotificationMode;
use App\Notification\NotificationPolicy;
use App\Notification\NotificationPolicyResolver;
use PHPUnit\Framework\TestCase;

final class NotificationPolicyResolverTest extends TestCase
{
    /** @var array<string, string> */
    private array $previousEnv = [];

    protected function setUp(): void
    {
        foreach (['NOTIF_MODE', 'NOTIF_DISABLED_CATEGORIES'] as $key) {
            $this->previousEnv[$key] = $_ENV[$key] ?? '';
        }
        $_ENV['NOTIF_MODE'] = 'important';
        $_ENV['NOTIF_DISABLED_CATEGORIES'] = 'camera';
    }

    protected function tearDown(): void
    {
        foreach ($this->previousEnv as $key => $value) {
            if ($value === '') {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }
    }

    public function testResolveUsesEnvWhenNoFamily(): void
    {
        $resolver = NotificationPolicyResolver::fixed(NotificationPolicy::fromEnv());
        $policy = $resolver->resolve(null);

        $this->assertSame(NotificationMode::Important, $policy->mode);
        $this->assertTrue($policy->isCategoryDisabled(NotificationCategory::Camera));
        $this->assertFalse($policy->isCategoryDisabled(NotificationCategory::Hydraulic));
    }

    public function testFixedResolverIgnoresFamily(): void
    {
        $policy = new NotificationPolicy(NotificationMode::None, []);
        $resolver = NotificationPolicyResolver::fixed($policy);

        $this->assertSame(NotificationMode::None, $resolver->resolve('FFP3')->mode);
    }

    public function testFromAlertFamilyMapsMsp1(): void
    {
        $this->assertSame(NotificationFamily::Msp1, NotificationFamily::fromAlertFamily('MSP1'));
    }
}

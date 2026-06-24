<?php

declare(strict_types=1);

namespace Tests\Notification;

use App\Notification\NotificationCategory;
use App\Notification\NotificationMode;
use App\Notification\NotificationPolicy;
use App\Notification\Severity;
use PHPUnit\Framework\TestCase;

/**
 * Tests de NotificationPolicy : combinaison mode × catégorie et construction depuis l'env.
 */
final class NotificationPolicyTest extends TestCase
{
    /** @var array<string, string|null> */
    private array $previousEnv = [];

    protected function setUp(): void
    {
        foreach (['NOTIF_MODE', 'NOTIF_DISABLED_CATEGORIES'] as $key) {
            $this->previousEnv[$key] = $_ENV[$key] ?? null;
            unset($_ENV[$key]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->previousEnv as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }
    }

    public function testShouldSendRespectsModeThreshold(): void
    {
        $policy = new NotificationPolicy(NotificationMode::Important);

        self::assertTrue($policy->shouldSend(Severity::Critical));
        self::assertTrue($policy->shouldSend(Severity::Alert));
        self::assertFalse($policy->shouldSend(Severity::Info));
        self::assertFalse($policy->shouldSend(Severity::Diagnostic));
    }

    public function testDisabledCategoryIsBlockedEvenInFullMode(): void
    {
        $policy = new NotificationPolicy(NotificationMode::Full, [NotificationCategory::Camera]);

        self::assertFalse($policy->shouldSend(Severity::Critical, NotificationCategory::Camera));
        self::assertTrue($policy->shouldSend(Severity::Critical, NotificationCategory::Hydraulic));
        self::assertTrue($policy->isCategoryDisabled(NotificationCategory::Camera));
        self::assertFalse($policy->isCategoryDisabled(NotificationCategory::Hydraulic));
    }

    public function testNullCategoryIsNeverBlockedByCategoryFilter(): void
    {
        $policy = new NotificationPolicy(NotificationMode::Full, [NotificationCategory::System]);

        self::assertTrue($policy->shouldSend(Severity::Alert, null));
    }

    public function testFromEnvDefaultsToFullWhenUnset(): void
    {
        $policy = NotificationPolicy::fromEnv();

        self::assertSame(NotificationMode::Full, $policy->mode);
        self::assertTrue($policy->shouldSend(Severity::Diagnostic));
    }

    public function testFromEnvParsesModeAndDisabledCategories(): void
    {
        $_ENV['NOTIF_MODE'] = 'important';
        $_ENV['NOTIF_DISABLED_CATEGORIES'] = 'camera, lifecycle , bogus';

        $policy = NotificationPolicy::fromEnv();

        self::assertSame(NotificationMode::Important, $policy->mode);
        self::assertTrue($policy->isCategoryDisabled(NotificationCategory::Camera));
        self::assertTrue($policy->isCategoryDisabled(NotificationCategory::Lifecycle));
        // Une valeur inconnue ("bogus") est ignorée silencieusement.
        self::assertFalse($policy->isCategoryDisabled(NotificationCategory::Energy));
    }
}

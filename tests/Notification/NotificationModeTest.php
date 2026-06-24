<?php

declare(strict_types=1);

namespace Tests\Notification;

use App\Notification\NotificationMode;
use App\Notification\Severity;
use PHPUnit\Framework\TestCase;

/**
 * Tests de NotificationMode : parsing depuis chaîne et matrice mode × sévérité.
 */
final class NotificationModeTest extends TestCase
{
    public function testFromStringIsCaseInsensitiveAndTrimmed(): void
    {
        self::assertSame(NotificationMode::Important, NotificationMode::fromString('  IMPORTANT '));
        self::assertSame(NotificationMode::None, NotificationMode::fromString('none'));
        self::assertSame(NotificationMode::Partial, NotificationMode::fromString('Partial'));
    }

    public function testFromStringFallsBackToDefaultOnUnknownOrNull(): void
    {
        self::assertSame(NotificationMode::Full, NotificationMode::fromString(null));
        self::assertSame(NotificationMode::Full, NotificationMode::fromString('bogus'));
        self::assertSame(NotificationMode::Important, NotificationMode::fromString('bogus', NotificationMode::Important));
    }

    /**
     * @dataProvider allowsProvider
     */
    public function testAllowsMatrix(NotificationMode $mode, Severity $severity, bool $expected): void
    {
        self::assertSame($expected, $mode->allows($severity));
    }

    /**
     * @return array<string, array{0: NotificationMode, 1: Severity, 2: bool}>
     */
    public static function allowsProvider(): array
    {
        return [
            'none blocks critical' => [NotificationMode::None, Severity::Critical, false],
            'none blocks diagnostic' => [NotificationMode::None, Severity::Diagnostic, false],
            'important allows critical' => [NotificationMode::Important, Severity::Critical, true],
            'important allows alert' => [NotificationMode::Important, Severity::Alert, true],
            'important blocks info' => [NotificationMode::Important, Severity::Info, false],
            'important blocks diagnostic' => [NotificationMode::Important, Severity::Diagnostic, false],
            'partial allows info' => [NotificationMode::Partial, Severity::Info, true],
            'partial blocks diagnostic' => [NotificationMode::Partial, Severity::Diagnostic, false],
            'full allows diagnostic' => [NotificationMode::Full, Severity::Diagnostic, true],
        ];
    }
}

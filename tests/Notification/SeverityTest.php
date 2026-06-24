<?php

declare(strict_types=1);

namespace Tests\Notification;

use App\Notification\Severity;
use PHPUnit\Framework\TestCase;

/**
 * Tests de l'énumération Severity : codes P1..P4, priorités et cooldowns par défaut.
 */
final class SeverityTest extends TestCase
{
    public function testCodesAreP1ToP4(): void
    {
        self::assertSame('P1', Severity::Critical->code());
        self::assertSame('P2', Severity::Alert->code());
        self::assertSame('P3', Severity::Info->code());
        self::assertSame('P4', Severity::Diagnostic->code());
    }

    public function testPriorityIsOrderedFromCriticalToDiagnostic(): void
    {
        self::assertSame(1, Severity::Critical->priority());
        self::assertSame(2, Severity::Alert->priority());
        self::assertSame(3, Severity::Info->priority());
        self::assertSame(4, Severity::Diagnostic->priority());
    }

    public function testMoreCriticalSeveritiesHaveShorterDefaultCooldown(): void
    {
        self::assertLessThan(Severity::Alert->defaultCooldownSeconds(), Severity::Critical->defaultCooldownSeconds());
        self::assertLessThan(Severity::Info->defaultCooldownSeconds(), Severity::Alert->defaultCooldownSeconds());
        self::assertLessThan(Severity::Diagnostic->defaultCooldownSeconds(), Severity::Info->defaultCooldownSeconds());
    }

    public function testLabelsAreHumanReadable(): void
    {
        self::assertSame('Critique', Severity::Critical->label());
        self::assertSame('Diagnostic', Severity::Diagnostic->label());
    }
}

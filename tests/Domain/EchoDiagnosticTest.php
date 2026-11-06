<?php

declare(strict_types=1);

namespace Tests\Domain;

use App\Domain\EchoDiagnostic;
use PHPUnit\Framework\TestCase;

final class EchoDiagnosticTest extends TestCase
{
    public function testParsesFirmwareContract(): void
    {
        $d = EchoDiagnostic::parse('no_echo,8,0,0');
        self::assertNotNull($d);
        self::assertSame('no_echo', $d->status);
        self::assertSame(8, $d->timeouts);
        self::assertSame(0, $d->rejected);
        self::assertSame(0, $d->lastRejectedMm);

        $d = EchoDiagnostic::parse(' out_of_range,1,4,15 ');
        self::assertSame(['out_of_range', 1, 4, 15], [$d?->status, $d?->timeouts, $d?->rejected, $d?->lastRejectedMm]);
    }

    public function testRejectsMalformedValues(): void
    {
        foreach ([null, '', 'ok', 'ok,1,2', 'ok,1,2,3,4', 'burning,1,0,0', 'ok,-1,0,0', 'ok,a,0,0', "ok,1,0,0'; DROP"] as $raw) {
            self::assertNull(EchoDiagnostic::parse($raw), var_export($raw, true));
        }
    }

    public function testClampsCounters(): void
    {
        $d = EchoDiagnostic::parse('disabled,99999,300,70000');
        self::assertSame([255, 255, 65535], [$d?->timeouts, $d?->rejected, $d?->lastRejectedMm]);
    }

    public function testExplainsEachStatus(): void
    {
        self::assertStringContainsString('aucun écho (8 tir(s)', (string) EchoDiagnostic::explain('no_echo', 8));
        self::assertStringContainsString('dernier à 15 mm', (string) EchoDiagnostic::explain('out_of_range', 0, 4, 15));
        self::assertStringContainsString('désactivé', (string) EchoDiagnostic::explain('disabled'));
        self::assertNull(EchoDiagnostic::explain(null));
    }
}

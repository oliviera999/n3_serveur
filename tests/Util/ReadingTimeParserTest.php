<?php

declare(strict_types=1);

namespace Tests\Util;

use App\Util\ReadingTimeParser;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

class ReadingTimeParserTest extends TestCase
{
    public function testToUnixMsUsesEuropeParis(): void
    {
        $time = '2026-06-05 14:30:00';
        $expected = DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            $time,
            new DateTimeZone('Europe/Paris')
        );

        $this->assertNotFalse($expected);
        $this->assertSame($expected->getTimestamp() * 1000, ReadingTimeParser::toUnixMs($time));
        $this->assertSame($expected->getTimestamp(), ReadingTimeParser::toUnixSeconds($time));
    }

    public function testToUnixMsReturnsNullForInvalidInput(): void
    {
        $this->assertNull(ReadingTimeParser::toUnixMs('invalid'));
    }

    /**
     * Constat S10 (6.34.0) : le fuseau de stockage était codé en dur à `Europe/Paris`
     * alors que DisplayTime et Database::currentUtcOffset() — qui décident de
     * l'affichage et du fuseau de session MySQL — lisent `APP_TIMEZONE`. Changer ce
     * réglage aurait décalé silencieusement les horodatages Highcharts.
     */
    public function testHonoursAppTimezone(): void
    {
        $previous = $_ENV['APP_TIMEZONE'] ?? null;

        try {
            $_ENV['APP_TIMEZONE'] = 'UTC';
            $time = '2026-06-05 14:30:00';
            $expected = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $time, new DateTimeZone('UTC'));

            $this->assertNotFalse($expected);
            $this->assertSame($expected->getTimestamp(), ReadingTimeParser::toUnixSeconds($time));
        } finally {
            if ($previous === null) {
                unset($_ENV['APP_TIMEZONE']);
            } else {
                $_ENV['APP_TIMEZONE'] = $previous;
            }
        }
    }

    /**
     * Un fuseau invalide ne doit pas lever : repli sur le défaut historique.
     */
    public function testInvalidAppTimezoneFallsBackToDefault(): void
    {
        $previous = $_ENV['APP_TIMEZONE'] ?? null;

        try {
            $_ENV['APP_TIMEZONE'] = 'Pas/UnFuseau';
            $time = '2026-06-05 14:30:00';
            $expected = DateTimeImmutable::createFromFormat(
                'Y-m-d H:i:s',
                $time,
                new DateTimeZone('Europe/Paris')
            );

            $this->assertNotFalse($expected);
            $this->assertSame($expected->getTimestamp(), ReadingTimeParser::toUnixSeconds($time));
        } finally {
            if ($previous === null) {
                unset($_ENV['APP_TIMEZONE']);
            } else {
                $_ENV['APP_TIMEZONE'] = $previous;
            }
        }
    }
}

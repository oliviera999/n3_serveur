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
}

<?php

declare(strict_types=1);

namespace Tests\Util;

use App\Util\FeedingScheduleValidator;
use PHPUnit\Framework\TestCase;

/**
 * Miroir côté serveur des tests firmware (test_feeding_slot_matcher.cpp) :
 * plage [0..23] des horaires + contrainte distinct/croissant.
 */
class FeedingScheduleValidatorTest extends TestCase
{
    public function testAssertHourRangesAcceptsValidHours(): void
    {
        $validated = FeedingScheduleValidator::assertHourRanges([
            'bouffeMatin' => '8',
            'bouffeMidi' => 12,
            'bouffeSoir' => '20',
            'tempsGros' => 999, // paramètre hors scope nourrissage : ignoré
        ]);

        $this->assertSame(['bouffeMatin' => 8, 'bouffeMidi' => 12, 'bouffeSoir' => 20], $validated);
    }

    public function testAssertHourRangesIgnoresAbsentParams(): void
    {
        $this->assertSame(
            ['bouffeMidi' => 0],
            FeedingScheduleValidator::assertHourRanges(['bouffeMidi' => 0])
        );
        $this->assertSame([], FeedingScheduleValidator::assertHourRanges(['mail' => 'a@b.c']));
    }

    public function testAssertHourRangesRejectsOutOfRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FeedingScheduleValidator::assertHourRanges(['bouffeMatin' => 24]);
    }

    public function testAssertHourRangesRejectsNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FeedingScheduleValidator::assertHourRanges(['bouffeSoir' => -1]);
    }

    public function testAssertHourRangesRejectsNonNumeric(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FeedingScheduleValidator::assertHourRanges(['bouffeMidi' => 'midi']);
    }

    public function testAssertHourRangesRejectsNonInteger(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FeedingScheduleValidator::assertHourRanges(['bouffeMatin' => '8.5']);
    }

    public function testIsStrictlyIncreasing(): void
    {
        $this->assertTrue(FeedingScheduleValidator::isStrictlyIncreasing(8, 12, 20));
        $this->assertTrue(FeedingScheduleValidator::isStrictlyIncreasing(0, 1, 23));
        $this->assertFalse(FeedingScheduleValidator::isStrictlyIncreasing(8, 8, 20));   // doublon
        $this->assertFalse(FeedingScheduleValidator::isStrictlyIncreasing(12, 8, 20));  // désordre
        $this->assertFalse(FeedingScheduleValidator::isStrictlyIncreasing(8, 20, 12));  // désordre
    }

    public function testCrossFieldWarningNullWhenIncreasing(): void
    {
        $current = ['bouffeMatin' => 8, 'bouffeMidi' => 12, 'bouffeSoir' => 20];
        $this->assertNull(FeedingScheduleValidator::crossFieldWarning($current, ['bouffeMidi' => 13]));
    }

    public function testCrossFieldWarningMergesIncomingOverCurrent(): void
    {
        // Persistés {8,12,20} ; on passe matin à 15 -> trio {15,12,20} non croissant
        $current = ['bouffeMatin' => 8, 'bouffeMidi' => 12, 'bouffeSoir' => 20];
        $warning = FeedingScheduleValidator::crossFieldWarning($current, ['bouffeMatin' => 15]);

        $this->assertNotNull($warning);
        $this->assertStringContainsString('matin=15h', $warning);
    }

    public function testCrossFieldWarningNullWhenTrioIncomplete(): void
    {
        // Aucune valeur persistée pour midi/soir : impossible de se prononcer
        $this->assertNull(FeedingScheduleValidator::crossFieldWarning([], ['bouffeMatin' => 8]));
        $this->assertNull(FeedingScheduleValidator::crossFieldWarning(
            ['bouffeMatin' => 8, 'bouffeMidi' => null],
            ['bouffeSoir' => 20]
        ));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Util;

use App\Util\DisplayTime;
use PHPUnit\Framework\TestCase;

/**
 * Conversion heure serveur (Europe/Paris) <-> heure d'affichage (Africa/Casablanca).
 */
final class DisplayTimeTest extends TestCase
{
    protected function setUp(): void
    {
        // Fuseau serveur déterministe pour les conversions testées.
        $_ENV['APP_TIMEZONE'] = 'Europe/Paris';
    }

    public function testToDisplayConvertsSummerOffsetMinusOneHour(): void
    {
        // Été : Paris UTC+2, Casablanca UTC+1 → -1 h.
        $this->assertSame(
            '15/07/2024 07:30:00',
            DisplayTime::toDisplay('2024-07-15 08:30:00')
        );
    }

    public function testToDisplayWinterSameHour(): void
    {
        // Hiver (hors Ramadan) : Paris UTC+1, Casablanca UTC+1 → 0 h.
        $this->assertSame(
            '15/01/2024 08:30:00',
            DisplayTime::toDisplay('2024-01-15 08:30:00')
        );
    }

    public function testToDisplayCustomFormat(): void
    {
        $this->assertSame(
            '2024-07-15T07:30',
            DisplayTime::toDisplay('2024-07-15 08:30:00', 'Y-m-d\TH:i')
        );
    }

    public function testToDisplayNullOrEmptyReturnsEmptyString(): void
    {
        $this->assertSame('', DisplayTime::toDisplay(null));
        $this->assertSame('', DisplayTime::toDisplay('   '));
    }

    public function testToServerIsInverseOfToDisplayInSummer(): void
    {
        // L'utilisateur saisit une heure de Casablanca ; on la stocke en heure serveur.
        $this->assertSame(
            '2024-07-15 08:30:00',
            DisplayTime::toServer('2024-07-15 07:30:00')
        );
    }

    public function testRoundTripPreservesInstant(): void
    {
        $server = '2024-07-15 08:30:00';
        $display = DisplayTime::toDisplay($server, 'Y-m-d H:i:s');
        $this->assertSame($server, DisplayTime::toServer($display));
    }

    public function testToDisplayAcceptsDateTimeImmutableFromAbstractDataController(): void
    {
        // AbstractDataController passe start_date/end_date en DateTimeImmutable (heure serveur).
        $dt = new \DateTimeImmutable('2024-07-15 08:30:00', new \DateTimeZone('Europe/Paris'));

        $this->assertSame(
            '15/07/2024 07:30:00',
            DisplayTime::toDisplay($dt)
        );
    }
}

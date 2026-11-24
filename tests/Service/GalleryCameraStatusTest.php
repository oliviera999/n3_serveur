<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\GalleryCameraStatus;
use PHPUnit\Framework\TestCase;

/**
 * Statut réel des caméras du timelapse (remplace le « EN LIGNE » fixe).
 */
final class GalleryCameraStatusTest extends TestCase
{
    private const NOW = 1795600000;

    protected function setUp(): void
    {
        $_ENV['APP_TIMEZONE'] = 'Europe/Paris';
    }

    private static function serverTime(int $ts): string
    {
        return (new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone('Europe/Paris'))->format('Y-m-d H:i:s');
    }

    public function testRecentPhotoIsOnline(): void
    {
        $s = GalleryCameraStatus::evaluate(self::NOW - 300, self::serverTime(self::NOW - 60), self::NOW);
        $this->assertSame('online', $s['state']);
        $this->assertSame('dernière photo il y a 5 min', $s['detail']);
    }

    /** Cas msp1 du 24/11/2026 : la caméra interroge le serveur mais n'envoie plus de photo. */
    public function testContactWithoutPhotoIsFlagged(): void
    {
        $s = GalleryCameraStatus::evaluate(self::NOW - 4 * 86400, self::serverTime(self::NOW - 120), self::NOW);
        $this->assertSame('no_photo', $s['state']);
        $this->assertStringStartsWith('aucune photo depuis le ', $s['detail']);
    }

    /** Cas ffp3 du 24/11/2026 : ni photo ni contact depuis 13 jours. */
    public function testSilentCameraIsOffline(): void
    {
        $s = GalleryCameraStatus::evaluate(self::NOW - 13 * 86400, self::serverTime(self::NOW - 13 * 86400 + 10), self::NOW);
        $this->assertSame('offline', $s['state']);
        $this->assertSame('HORS LIGNE', $s['label']);
        $this->assertStringStartsWith('dernier contact le ', $s['detail']);
    }

    /**
     * Régression 6.65.0 : getLastBoardRequest() rend 'd/m/Y H:i:s' en heure de Casablanca
     * (BoardRepository::formatTimestamp) ; ce format était ignoré → msp1 « HORS LIGNE ».
     */
    public function testDisplayFormattedContactFromRepositoryIsRead(): void
    {
        $display = (new \DateTimeImmutable('@' . (self::NOW - 180)))
            ->setTimezone(new \DateTimeZone('Africa/Casablanca'))->format('d/m/Y H:i:s');
        $s = GalleryCameraStatus::evaluate(self::NOW - 4 * 86400, $display, self::NOW);
        $this->assertSame('no_photo', $s['state']);
        $this->assertNotSame('', $s['last_contact']);
    }

    public function testUnreadableContactIsIgnored(): void
    {
        $this->assertSame('unknown', GalleryCameraStatus::evaluate(null, 'pas une date', self::NOW)['state']);
    }

    public function testNothingKnownIsUnknown(): void
    {
        $this->assertSame('unknown', GalleryCameraStatus::evaluate(null, null, self::NOW)['state']);
        $this->assertSame('unknown', GalleryCameraStatus::evaluate(null, '', self::NOW)['state']);
    }
}

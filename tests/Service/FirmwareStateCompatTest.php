<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\FirmwareStateCompat;
use PHPUnit\Framework\TestCase;

/**
 * Couche de compatibilité du contrat « état plat firmware » pour la flotte déployée
 * (n3pp ≤ 4.65, msp ≤ 2.66, non reflashables à court terme).
 *
 * Vérifie les trois modes (`off` / `safe` / `full`), le retrait des GPIO d'état miroir
 * (boucle pompe n3pp) et l'omission des valeurs de configuration hors plage.
 */
final class FirmwareStateCompatTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_ENV[FirmwareStateCompat::SETTING_KEY]);
    }

    private function withMode(?string $mode): FirmwareStateCompat
    {
        if ($mode === null) {
            unset($_ENV[FirmwareStateCompat::SETTING_KEY]);
        } else {
            $_ENV[FirmwareStateCompat::SETTING_KEY] = $mode;
        }

        return new FirmwareStateCompat();
    }

    public function testDefaultModeIsOffSoFlatKeysAreNotMerged(): void
    {
        $compat = $this->withMode(null);

        self::assertSame(FirmwareStateCompat::MODE_OFF, $compat->mode());
        self::assertFalse($compat->mergesFlatKeys());
    }

    public function testUnknownModeFallsBackToOff(): void
    {
        $compat = $this->withMode('nimporte-quoi');

        self::assertSame(FirmwareStateCompat::MODE_OFF, $compat->mode());
    }

    public function testSafeAndFullModesMergeFlatKeys(): void
    {
        self::assertTrue($this->withMode(FirmwareStateCompat::MODE_SAFE)->mergesFlatKeys());
        self::assertTrue($this->withMode(FirmwareStateCompat::MODE_FULL)->mergesFlatKeys());
    }

    public function testSafeModeDropsMirroredPumpStateForN3pp(): void
    {
        // GPIO 12 = etatPompe recopié par le serveur depuis le POST : le renvoyer ferait
        // ré-appliquer au firmware l'état qu'il vient lui-même de mesurer (boucle).
        $clean = $this->withMode(FirmwareStateCompat::MODE_SAFE)
            ->sanitize(['12' => '1', '13' => '1', '107' => '300'], FirmwareStateCompat::MODULE_N3PP);

        self::assertArrayNotHasKey('12', $clean);
        self::assertSame('1', $clean['13']);   // one-shot arrosage manuel : conservé
        self::assertSame('300', $clean['107']);
    }

    public function testFullModeKeepsEverythingIncludingMirroredState(): void
    {
        $flat = ['12' => '1', '103' => '999999', '107' => '0'];

        $clean = $this->withMode(FirmwareStateCompat::MODE_FULL)
            ->sanitize($flat, FirmwareStateCompat::MODULE_N3PP);

        self::assertSame($flat, $clean);
    }

    public function testSafeModeOmitsOutOfRangeConfigValues(): void
    {
        $compat = $this->withMode(FirmwareStateCompat::MODE_SAFE);

        $clean = $compat->sanitize([
            '103' => '999999',  // SeuilPontDiv hors plage ADC -> veille infinie permanente
            '107' => '0',       // FreqWakeUp invalide (firmware exige 1..86400)
            '102' => '4095',    // borne haute admise
            '112' => '1',       // booléen valide
            '110' => '2',       // booléen hors plage
        ], FirmwareStateCompat::MODULE_N3PP);

        self::assertArrayNotHasKey('103', $clean);
        self::assertArrayNotHasKey('107', $clean);
        self::assertArrayNotHasKey('110', $clean);
        self::assertSame('4095', $clean['102']);
        self::assertSame('1', $clean['112']);
    }

    public function testSafeModeAppliesModuleSpecificRanges(): void
    {
        $compat = $this->withMode(FirmwareStateCompat::MODE_SAFE);

        // 104 : HeureArrosage (0..23) côté n3pp, angle servo (0..180) côté msp1.
        $n3pp = $compat->sanitize(['104' => '90'], FirmwareStateCompat::MODULE_N3PP);
        $msp = $compat->sanitize(['104' => '90'], FirmwareStateCompat::MODULE_MSP1);

        self::assertArrayNotHasKey('104', $n3pp);
        self::assertSame('90', $msp['104']);
    }

    public function testSafeModeLetsUnknownKeysThrough(): void
    {
        // Garde-fou borné au risque connu : une clé non répertoriée (actionneur, GPIO
        // d'un firmware plus récent) n'est pas filtrée.
        $clean = $this->withMode(FirmwareStateCompat::MODE_SAFE)
            ->sanitize(['16' => '1', '150' => 'xyz'], FirmwareStateCompat::MODULE_N3PP);

        self::assertSame('1', $clean['16']);
        self::assertSame('xyz', $clean['150']);
    }

    public function testSafeModeOmitsNonNumericConfigValue(): void
    {
        $clean = $this->withMode(FirmwareStateCompat::MODE_SAFE)
            ->sanitize(['107' => '', '112' => 'checked'], FirmwareStateCompat::MODULE_MSP1);

        self::assertSame([], $clean);
    }
}

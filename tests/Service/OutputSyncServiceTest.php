<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\OutputSyncService;
use PHPUnit\Framework\TestCase;

/**
 * Verrouille le mapping GPIO canonique du serveur (OutputSyncService::GPIO_MAPPING).
 *
 * CONTRAT (firmware↔serveur) : cette table doit rester strictement synchronisée avec
 * la table firmware GPIOMap::ALL_MAPPINGS (n3_firmwires ffp5cs gpio_mapping.h), couverte
 * côté firmware par test_gpio_lookup. Tout renommage/ajout/suppression non coordonné ici
 * casserait la lecture des dbvars par le firmware (web_server.cpp::fillDbVarsJson) → données
 * de config erronées / 401 HMAC. Ce test est le pendant serveur du test firmware.
 */
class OutputSyncServiceTest extends TestCase
{
    /** Mapping canonique attendu — DOIT rester identique à GPIOMap::ALL_MAPPINGS côté firmware. */
    private const EXPECTED = [
        // Actionneurs physiques
        2 => 'etatHeat',
        15 => 'etatUV',
        16 => 'etatPompeAqua',
        18 => 'etatPompeTank',
        // Config distante (dbvars)
        100 => 'mail',
        101 => 'mailNotif',
        102 => 'aqThreshold',
        103 => 'tankThreshold',
        104 => 'chauffageThreshold',
        105 => 'bouffeMatin',
        106 => 'bouffeMidi',
        107 => 'bouffeSoir',
        108 => 'bouffePetits',
        109 => 'bouffeGros',
        110 => 'resetMode',
        111 => 'tempsGros',
        112 => 'tempsPetits',
        113 => 'tempsRemplissageSec',
        114 => 'limFlood',
        115 => 'WakeUp',
        116 => 'FreqWakeUp',
    ];

    public function testGpioMappingMatchesCanonicalContract(): void
    {
        // Égalité stricte (clés + valeurs + ordre) : détecte tout renommage/ajout/suppression.
        $this->assertSame(self::EXPECTED, OutputSyncService::getGpioMapping());
    }

    public function testFirmwareCriticalConfigFields(): void
    {
        $m = OutputSyncService::getGpioMapping();
        // Champs config lus par le firmware (web_server.cpp::fillDbVarsJson).
        $this->assertSame('aqThreshold', $m[102]);
        $this->assertSame('tankThreshold', $m[103]);
        $this->assertSame('chauffageThreshold', $m[104]);
        $this->assertSame('bouffeMatin', $m[105]);
        $this->assertSame('tempsRemplissageSec', $m[113]);
        $this->assertSame('limFlood', $m[114]);
        $this->assertSame('FreqWakeUp', $m[116]);
    }

    public function testGpio117IsNotInContract(): void
    {
        // GPIO 117 (forçage pompe aquarium) est serveur-uniquement, absent du contrat POST/GET
        // firmware (cf. note gpio_mapping.h). Il ne doit pas apparaître ici.
        $this->assertArrayNotHasKey(117, OutputSyncService::getGpioMapping());
    }

    public function testMappingIntegrity(): void
    {
        $m = OutputSyncService::getGpioMapping();
        $this->assertCount(21, $m);
        // Noms uniques (pas de collision de champ POST).
        $this->assertSame(count($m), count(array_unique($m)));
        // Toutes les clés sont des GPIO entiers, toutes les valeurs des chaînes non vides.
        foreach ($m as $gpio => $name) {
            $this->assertIsInt($gpio);
            $this->assertIsString($name);
            $this->assertNotSame('', $name);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Source unique de vérité du mapping GPIO ↔ paramètre pour le module FFP3.
 *
 * Avant : le mapping existait en double — `OutputRepository::PARAMETER_GPIO_MAP`
 * (nom de paramètre UI → GPIO) et `OutputSyncService::GPIO_MAPPING`
 * (GPIO → propriété SensorData) — avec, en plus, le pont
 * `OutputService::PROPERTY_TO_PARAM_NAME` (propriété → nom de paramètre).
 *
 * Après : tout dérive d'ici.
 *  - {@see gpioToProperty()}   GPIO → propriété SensorData (utilisé par OutputSyncService,
 *                              OutputCacheService, OutputService::getLastDataStates).
 *  - {@see propertyToParam()}  propriété SensorData → nom de paramètre UI/API (pont).
 *  - {@see parameterGpioMap()} nom de paramètre UI/API → GPIO (utilisé par OutputRepository).
 *
 * Les valeurs sont strictement préservées : mêmes GPIO, mêmes noms.
 */
final class Ffp3GpioMap
{
    /**
     * Mapping des GPIO vers les propriétés SensorData (source canonique).
     *
     * @var array<int, string>
     */
    private const GPIO_TO_PROPERTY = [
        // Actionneurs physiques
        2 => 'etatHeat',           // Chauffage
        15 => 'etatUV',            // Lumière
        16 => 'etatPompeAqua',     // Pompe aquarium
        18 => 'etatPompeTank',     // Pompe réservoir (1=ON côté UI/GET/POST ; PumpService legacy = relais actif-bas)

        // Configuration
        100 => 'mail',             // Email (string)
        101 => 'mailNotif',        // Notifications (string)
        102 => 'aqThreshold',      // Seuil aquarium
        103 => 'tankThreshold',    // Seuil réservoir
        104 => 'chauffageThreshold', // Seuil chauffage
        105 => 'bouffeMatin',      // Heure nourrissage matin
        106 => 'bouffeMidi',       // Heure nourrissage midi
        107 => 'bouffeSoir',       // Heure nourrissage soir

        // Commandes nourrissage (flags remis à 0 par ESP32 après exécution)
        108 => 'bouffePetits',     // Flag nourrissage petits poissons
        109 => 'bouffeGros',       // Flag nourrissage gros poissons
        110 => 'resetMode',        // Reset ESP32 (aligné gpio_mapping.h / VARIABLE_NAMING.md)

        // Paramètres timing
        111 => 'tempsGros',        // Temps nourrissage gros
        112 => 'tempsPetits',      // Temps nourrissage petits
        113 => 'tempsRemplissageSec', // Temps remplissage
        114 => 'limFlood',         // Limite débordement
        115 => 'WakeUp',           // WakeUp forcé (v11.172: harmonisé avec firmware)
        116 => 'FreqWakeUp',       // Fréquence réveil (v11.172: harmonisé avec firmware)

        // Angles servo nourrissage (GPIO 118-123)
        118 => 'angleReposGros',
        119 => 'angleDistribGros',
        120 => 'angleInterGros',
        121 => 'angleReposPetits',
        122 => 'angleDistribPetits',
        123 => 'angleInterPetits',
    ];

    /**
     * Pont propriété SensorData → nom de paramètre UI/API (table outputs).
     *
     * Seules les propriétés persistées comme « paramètres » de la page contrôle
     * figurent ici. L'ordre détermine l'ordre de {@see parameterGpioMap()}.
     *
     * @var array<string, string>
     */
    private const PROPERTY_TO_PARAM = [
        'mail' => 'mail',
        'mailNotif' => 'mailNotif',
        'aqThreshold' => 'aqThr',
        'tankThreshold' => 'taThr',
        'chauffageThreshold' => 'chauff',
        'bouffeMatin' => 'bouffeMatin',
        'bouffeMidi' => 'bouffeMidi',
        'bouffeSoir' => 'bouffeSoir',
        'tempsGros' => 'tempsGros',
        'tempsPetits' => 'tempsPetits',
        'tempsRemplissageSec' => 'tempsRemplissageSec',
        'limFlood' => 'limFlood',
        'WakeUp' => 'WakeUp',
        'FreqWakeUp' => 'FreqWakeUp',
        'angleReposGros' => 'angleReposGros',
        'angleDistribGros' => 'angleDistribGros',
        'angleInterGros' => 'angleInterGros',
        'angleReposPetits' => 'angleReposPetits',
        'angleDistribPetits' => 'angleDistribPetits',
        'angleInterPetits' => 'angleInterPetits',
    ];

    /**
     * GPIO → propriété SensorData.
     *
     * @return array<int, string>
     */
    public static function gpioToProperty(): array
    {
        return self::GPIO_TO_PROPERTY;
    }

    /**
     * Propriété SensorData → nom de paramètre UI/API.
     *
     * @return array<string, string>
     */
    public static function propertyToParam(): array
    {
        return self::PROPERTY_TO_PARAM;
    }

    /**
     * Nom de paramètre UI/API → GPIO, dérivé de la source canonique.
     *
     * Construit en parcourant {@see PROPERTY_TO_PARAM} (ordre stable) puis en
     * résolvant le GPIO via l'inverse de {@see GPIO_TO_PROPERTY}.
     *
     * @return array<string, int>
     */
    public static function parameterGpioMap(): array
    {
        $propertyToGpio = array_flip(self::GPIO_TO_PROPERTY);

        $map = [];
        foreach (self::PROPERTY_TO_PARAM as $property => $paramName) {
            if (isset($propertyToGpio[$property])) {
                $map[$paramName] = $propertyToGpio[$property];
            }
        }

        return $map;
    }
}

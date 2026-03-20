<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Service utilitaire pour les GPIO FFP3.
 * Fournit le mapping GPIO ↔ propriétés SensorData utilisé par OutputService et OutputCacheService.
 */
class OutputSyncService
{
    /**
     * Mapping des champs SensorData vers les GPIO
     */
    private const GPIO_MAPPING = [
        // Actionneurs physiques
        2 => 'etatHeat',           // Chauffage
        15 => 'etatUV',            // Lumière
        16 => 'etatPompeAqua',     // Pompe aquarium
        18 => 'etatPompeTank',     // Pompe réservoir (logique inversée)
        
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
    ];

    /**
     * Retourne le mapping GPIO pour référence
     * 
     * @return array<int, string>
     */
    public static function getGpioMapping(): array
    {
        return self::GPIO_MAPPING;
    }
}

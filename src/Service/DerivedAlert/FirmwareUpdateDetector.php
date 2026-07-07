<?php

declare(strict_types=1);

namespace App\Service\DerivedAlert;

/**
 * Détection pure d'une mise à jour de firmware (OTA réussie / reflash) à partir
 * de la colonne `version` postée par l'appareil à chaque cycle.
 *
 * Un changement de version entre deux lignes du MÊME capteur = mise à jour.
 * Garde `sensor` : si un autre appareil poste dans la même table, l'état est
 * ré-initialisé en silence (comparer les versions de deux appareils différents
 * produirait des faux positifs). Premier passage : initialisation silencieuse.
 *
 * Utilisé par les trois familles capteurs (FFP3/N3PP/MSP1). La CAM
 * (uploadphotosserver) poste sa version par un canal distinct (POST version,
 * hors tables data) et reste hors périmètre — ses mails OTA restent ESP.
 */
final class FirmwareUpdateDetector
{
    /**
     * Met à jour l'état (`lastFirmwareVersion`/`lastFirmwareSensor`) et retourne
     * les versions avant/après si une mise à jour vient d'être détectée.
     *
     * @param array<string, mixed> $row   Dernière ligne data (colonnes version/sensor)
     * @param array<string, mixed> $state État persistant inter-runs
     *
     * @return array{from: string, to: string}|null
     */
    public static function detect(array $row, array &$state): ?array
    {
        $version = isset($row['version']) && is_string($row['version']) ? trim($row['version']) : '';
        if ($version === '' || $version === 'N/A') {
            return null;
        }
        $sensor = isset($row['sensor']) && is_string($row['sensor']) ? trim($row['sensor']) : '';

        $previousVersion = $state['lastFirmwareVersion'] ?? null;
        $previousSensor = $state['lastFirmwareSensor'] ?? null;
        $state['lastFirmwareVersion'] = $version;
        $state['lastFirmwareSensor'] = $sensor;

        if (!is_string($previousVersion) || $previousVersion === '') {
            return null; // premier passage : initialiser sans notifier
        }
        if ($previousSensor !== $sensor) {
            return null; // autre appareil : ré-initialisation silencieuse
        }
        if ($previousVersion === $version) {
            return null;
        }

        return ['from' => $previousVersion, 'to' => $version];
    }
}

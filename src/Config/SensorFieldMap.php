<?php

declare(strict_types=1);

namespace App\Config;

/**
 * SOURCE UNIQUE des capteurs physiques par famille et de leurs diagnostics associés.
 *
 * Repris par :
 *  - {@see \App\Mcp\Support\FieldCatalog::sensorFields()} (iot_sensor_health, iot_latest) ;
 *  - {@see \App\Mcp\Health\HealthReportBuilder} (capteurs muets / figés du rapport de santé) ;
 *  - {@see \App\Mcp\Tools\SensorHealthTool::analyse()} (statut `disabled`, figement conditionné) ;
 *  - {@see \App\Service\DerivedAlert\Ffp3DerivedAlertService} (colonnes de diagnostic d'écho) ;
 *  - {@see \App\Controller\AbstractDataController::stuckSensors()} (alerte « figé » des pages) ;
 *  - {@see \App\Util\Ffp3WaterLevelUnit} (niveaux d'eau FFP3 stockés en mm).
 *
 * Toute nouvelle colonne de capteur ou de diagnostic s'ajoute ICI, jamais dans une liste recopiée.
 */
final class SensorFieldMap
{
    /** Statut du diagnostic d'écho firmware (ffp5cs ≥ 15.33) : capteur mis hors service par le firmware. */
    public const STATUS_DISABLED = 'disabled';

    /**
     * Capteurs PHYSIQUES suivis (muet / figé). Exclus volontairement : luminosité (constante la
     * nuit), pression FFP3 (0 sans BME280), champs de marée calculés, états d'actionneurs,
     * réglages — tous légitimement constants ou vides.
     */
    private const PHYSICAL = [
        'ffp3' => ['TempAir', 'Humidite', 'TempEau', 'EauAquarium', 'EauReserve', 'EauPotager'],
        'n3pp' => ['TempAir', 'Humidite', 'Humid1', 'Humid2', 'Humid3', 'Humid4', 'HumidMoy'],
        'msp1' => ['TempAirInt', 'TempAirExt', 'HumidAirInt', 'HumidAirExt', 'Pression', 'HumidSol', 'TempEau'],
        'energie' => ['BatterieV', 'ConsoV'],
    ];

    /** Niveaux d'eau FFP3 : distances capteur → surface, stockées en mm, affichées en cm. */
    public const FFP3_WATER_LEVELS = ['EauAquarium', 'EauReserve', 'EauPotager'];

    /**
     * Préfixe des colonnes de diagnostic d'écho firmware par capteur : `{prefix}Status`,
     * `{prefix}Timeouts`, `{prefix}Rejected`, `{prefix}RawMm` (migration 2026_11_ffp3_echo_diag.sql).
     */
    private const ECHO_DIAG_PREFIX = [
        'ffp3' => ['EauAquarium' => 'wlAqua', 'EauPotager' => 'wlPota'],
    ];

    /** Suffixes des colonnes de diagnostic d'écho. */
    private const ECHO_DIAG_SUFFIXES = ['Status', 'Timeouts', 'Rejected', 'RawMm'];

    /**
     * Grandeurs censées BOUGER, donc jugées « figées » par le rapport de santé quand elles se
     * répètent : champ => colonne d'activité exigée (null = toujours attendu en mouvement).
     * Aquarium et potager suivent les marées ; la réserve (cuve IBC) ne bouge que si la pompe de
     * réserve tourne : immobile pompe à l'arrêt, elle est normale. Température ou humidité (DHT au
     * % entier) peuvent rester stables des heures : elles ne sont jugées que « muettes ».
     */
    private const FROZEN_CHECK = [
        'ffp3' => ['EauAquarium' => null, 'EauPotager' => null, 'EauReserve' => 'etatPompeTank'],
    ];

    /**
     * @return list<string>
     */
    public static function physical(string $family): array
    {
        return self::PHYSICAL[$family] ?? [];
    }

    /**
     * Colonne de statut du diagnostic d'écho (ex. `wlPotaStatus`), ou null si le capteur n'en a pas.
     */
    public static function echoStatusColumn(string $family, string $field): ?string
    {
        $prefix = self::ECHO_DIAG_PREFIX[$family][$field] ?? null;

        return $prefix !== null ? $prefix . 'Status' : null;
    }

    /**
     * Préfixe des colonnes de diagnostic d'écho (ex. `wlAqua`), ou null.
     */
    public static function echoPrefix(string $family, string $field): ?string
    {
        return self::ECHO_DIAG_PREFIX[$family][$field] ?? null;
    }

    /**
     * Toutes les colonnes de diagnostic d'écho de la famille (ex. pour la liste blanche MCP).
     *
     * @return list<string>
     */
    public static function echoColumns(string $family): array
    {
        $out = [];
        foreach (self::ECHO_DIAG_PREFIX[$family] ?? [] as $prefix) {
            foreach (self::ECHO_DIAG_SUFFIXES as $suffix) {
                $out[] = $prefix . $suffix;
            }
        }

        return $out;
    }

    /**
     * Capteurs de la famille jugés « figés » par le rapport de santé.
     *
     * @return list<string>
     */
    public static function frozenCheckedFields(string $family): array
    {
        return array_keys(self::FROZEN_CHECK[$family] ?? []);
    }

    /**
     * Colonne d'activité sans laquelle une valeur répétée n'est PAS un figement (ex. pompe de
     * réserve pour EauReserve), ou null.
     */
    public static function frozenActivityColumn(string $family, string $field): ?string
    {
        return self::FROZEN_CHECK[$family][$field] ?? null;
    }

    /**
     * Colonnes auxiliaires à lire avec ces capteurs pour les analyser (statut d'écho, activité).
     *
     * @param list<string> $fields
     * @return list<string>
     */
    public static function auxiliaryColumns(string $family, array $fields): array
    {
        $out = [];
        foreach ($fields as $field) {
            foreach ([self::echoStatusColumn($family, $field), self::frozenActivityColumn($family, $field)] as $column) {
                if ($column !== null && !in_array($column, $out, true) && !in_array($column, $fields, true)) {
                    $out[] = $column;
                }
            }
        }

        return $out;
    }

    public static function isDisabledStatus(mixed $status): bool
    {
        return is_string($status) && strtolower(trim($status)) === self::STATUS_DISABLED;
    }

    /**
     * Capteurs déclarés `disabled` par le diagnostic d'écho d'une ligne de mesure (clé = capteur,
     * valeur = colonne de statut). Colonne absente ou vide = pas de diagnostic, donc rien.
     *
     * @param array<string, mixed> $row
     * @return array<string, string>
     */
    public static function disabledInRow(string $family, array $row): array
    {
        $out = [];
        foreach (self::ECHO_DIAG_PREFIX[$family] ?? [] as $field => $_) {
            $column = (string) self::echoStatusColumn($family, $field);
            if (self::isDisabledStatus($row[$column] ?? null)) {
                $out[$field] = $column;
            }
        }

        return $out;
    }
}

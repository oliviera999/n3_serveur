<?php

declare(strict_types=1);

namespace App\Service;

use App\Util\ReadingTimeParser;

/**
 * Service de préparation des données pour les graphiques Highcharts
 * 
 * Extrait la logique de formatting des données depuis les contrôleurs
 * pour une meilleure séparation des responsabilités
 */
class ChartDataService
{
    /**
     * Prépare toutes les séries de données pour Highcharts
     * 
     * @param array $readings Lectures capteurs (ordre DESC de la DB)
     * @return array Tableau associatif des séries encodées en JSON
     */
    public function prepareSeriesData(array $readings): array
    {
        // Utilitaires internes (JSON injecte dans bloc <script> Twig via |raw : on durcit
        // l'echappement avec JSON_HEX_TAG/HEX_AMP/HEX_QUOT/HEX_APOS pour eviter toute
        // rupture du contexte JS si une valeur (cas peu probable, mais defense en profondeur)
        // contenait un caractere actif).
        $col = static fn(array $rows, string $key): array => array_column($rows, $key);
        $encode = static fn(array $values): string => json_encode(
            array_reverse($values),
            JSON_NUMERIC_CHECK | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS
        );

        // Séries pour Highcharts (ordre chronologique inversé comme legacy)
        return [
            'EauAquarium'  => $encode($col($readings, 'EauAquarium')),
            'EauReserve'   => $encode($col($readings, 'EauReserve')),
            'EauPotager'   => $encode($col($readings, 'EauPotager')),
            'TempAir'      => $encode($col($readings, 'TempAir')),
            'TempEau'      => $encode($col($readings, 'TempEau')),
            'Humidite'     => $encode($col($readings, 'Humidite')),
            'Luminosite'   => $encode($col($readings, 'Luminosite')),
            'etatPompeAqua' => $encode($col($readings, 'etatPompeAqua')),
            'etatPompeTank' => $encode($col($readings, 'etatPompeTank')),
            'etatHeat'      => $encode($col($readings, 'etatHeat')),
            'etatUV'        => $encode($col($readings, 'etatUV')),
            'bouffePetits'  => $encode($col($readings, 'bouffePetits')),
            'bouffeGros'    => $encode($col($readings, 'bouffeGros')),
        ];
    }

    /**
     * Prépare les timestamps pour Highcharts (ms epoch UTC)
     * 
     * @param array $readings Lectures capteurs (ordre DESC de la DB)
     * @return string JSON array des timestamps en millisecondes
     */
    public function prepareTimestamps(array $readings): string
    {
        $col = static fn(array $rows, string $key): array => array_column($rows, $key);
        
        $reading_time_ts = array_map(
            static fn ($ts) => ReadingTimeParser::toUnixMs((string) $ts),
            $col(array_reverse($readings), 'reading_time')
        );
        
        return json_encode(
            $reading_time_ts,
            JSON_NUMERIC_CHECK | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS
        );
    }

    /**
     * Prépare les séries de données pour Highcharts à partir d'une liste de colonnes générique.
     * Utilisable par tous les modules (MSP1, N3PP, etc.) sans coder en dur les noms de colonnes.
     *
     * @param array $readings Lectures capteurs
     * @param string[] $columns Noms des colonnes à extraire
     * @return array<string, mixed> reading_time[] + une clé par colonne
     */
    public function prepareGenericSeries(array $readings, array $columns): array
    {
        $series = ['reading_time' => []];
        foreach ($columns as $col) {
            $series[$col] = [];
        }

        foreach ($readings as $r) {
            $ts = isset($r['reading_time'])
                ? (ReadingTimeParser::toUnixMs((string) $r['reading_time']) ?? 0)
                : 0;
            $series['reading_time'][] = $ts;
            foreach ($columns as $col) {
                $series[$col][] = isset($r[$col]) && $r[$col] !== null ? (float) $r[$col] : null;
            }
        }

        return $series;
    }

    /**
     * Extrait la dernière lecture de chaque capteur
     * 
     * @param array|null $lastReading Dernière lecture ou null
     * @return array Tableau associatif des dernières valeurs
     */
    public function extractLastReadings(?array $lastReading): array
    {
        if ($lastReading === null || $lastReading === []) {
            return [
                'tempair'   => 0,
                'tempeau'   => 0,
                'humi'      => 0,
                'lumi'      => 0,
                'eauaqua'   => 0,
                'eaureserve' => 0,
                'eaupota'   => 0,
                'time'      => date('Y-m-d H:i:s'),
            ];
        }

        return [
            'tempair'   => $lastReading['TempAir']       ?? 0,
            'tempeau'   => $lastReading['TempEau']       ?? 0,
            'humi'      => $lastReading['Humidite']      ?? 0,
            'lumi'      => $lastReading['Luminosite']    ?? 0,
            'eauaqua'   => $lastReading['EauAquarium']   ?? 0,
            'eaureserve' => $lastReading['EauReserve']    ?? 0,
            'eaupota'   => $lastReading['EauPotager']    ?? 0,
            'time'      => $lastReading['reading_time']  ?? date('Y-m-d H:i:s'),
        ];
    }
}


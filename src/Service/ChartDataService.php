<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;
use DateTimeZone;

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
        // Utilitaires internes
        $col = static fn(array $rows, string $key): array => array_column($rows, $key);
        $encode = static fn(array $values): string => json_encode(array_reverse($values), JSON_NUMERIC_CHECK);

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
        
        // Conversion en timestamp UTC (ms) en tenant compte du fuseau Europe/Paris
        $reading_time_ts = array_map(static function ($ts) {
            $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $ts, new DateTimeZone('Europe/Paris'));
            return $dt !== false ? $dt->getTimestamp() * 1000 : null;
        }, $col(array_reverse($readings), 'reading_time'));
        
        return json_encode($reading_time_ts, JSON_NUMERIC_CHECK);
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


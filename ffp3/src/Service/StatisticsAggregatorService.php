<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeInterface;

/**
 * Service d'agrégation des statistiques sur plusieurs capteurs
 * 
 * Consolide les appels répétitifs aux statistiques pour simplifier les contrôleurs
 */
class StatisticsAggregatorService
{
    private SensorStatisticsService $statsService;

    /**
     * Liste des colonnes pour lesquelles calculer les statistiques
     */
    private const SENSOR_COLUMNS = [
        'TempAir',
        'TempEau',
        'Humidite',
        'Luminosite',
        'EauAquarium',
        'EauReserve',
        'EauPotager',
    ];

    public function __construct(SensorStatisticsService $statsService)
    {
        $this->statsService = $statsService;
    }

    /**
     * Agrège toutes les statistiques (min, max, avg, stddev) pour tous les capteurs
     * 
     * @param DateTimeInterface|string $start Date/heure de début
     * @param DateTimeInterface|string $end   Date/heure de fin
     * @return array Tableau structuré : ['TempAir' => ['min' => X, 'max' => Y, ...], ...]
     */
    public function aggregateAllStats(DateTimeInterface|string $start, DateTimeInterface|string $end): array
    {
        $results = [];

        foreach (self::SENSOR_COLUMNS as $column) {
            $results[$column] = [
                'min'    => $this->statsService->min($column, $start, $end),
                'max'    => $this->statsService->max($column, $start, $end),
                'avg'    => $this->statsService->avg($column, $start, $end),
                'stddev' => $this->statsService->stddev($column, $start, $end),
            ];
        }

        return $results;
    }

    /**
     * Agrège les statistiques pour un seul capteur
     * 
     * @param string $column Nom de la colonne du capteur
     * @param DateTimeInterface|string $start Date/heure de début
     * @param DateTimeInterface|string $end   Date/heure de fin
     * @return array ['min' => X, 'max' => Y, 'avg' => Z, 'stddev' => W]
     */
    public function aggregateForSensor(string $column, DateTimeInterface|string $start, DateTimeInterface|string $end): array
    {
        return [
            'min'    => $this->statsService->min($column, $start, $end),
            'max'    => $this->statsService->max($column, $start, $end),
            'avg'    => $this->statsService->avg($column, $start, $end),
            'stddev' => $this->statsService->stddev($column, $start, $end),
        ];
    }

    /**
     * Formate les statistiques pour la compatibilité legacy (variables séparées)
     * 
     * @param array $stats Statistiques structurées depuis aggregateAllStats()
     * @return array Tableau aplati avec clés legacy (min_tempair, max_tempair, etc.)
     */
    public function flattenForLegacy(array $stats): array
    {
        $flattened = [];

        $mapping = [
            'TempAir'     => 'tempair',
            'TempEau'     => 'tempeau',
            'Humidite'    => 'humi',
            'Luminosite'  => 'lumi',
            'EauAquarium' => 'eauaqua',
            'EauReserve'  => 'eaureserve',
            'EauPotager'  => 'eaupota',
        ];

        foreach ($mapping as $column => $shortName) {
            if (isset($stats[$column])) {
                $flattened["min_{$shortName}"]    = $stats[$column]['min'];
                $flattened["max_{$shortName}"]    = $stats[$column]['max'];
                $flattened["avg_{$shortName}"]    = $stats[$column]['avg'];
                $flattened["stddev_{$shortName}"] = $stats[$column]['stddev'];
            }
        }

        return $flattened;
    }
}


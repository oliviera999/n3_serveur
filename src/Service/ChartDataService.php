<?php

declare(strict_types=1);

namespace App\Service;

use App\Util\ReadingTimeParser;
use App\Util\SeriesDownsampler;

/**
 * Service de préparation des données pour les graphiques Highcharts
 *
 * Extrait la logique de formatting des données depuis les contrôleurs
 * pour une meilleure séparation des responsabilités
 */
class ChartDataService
{
    /**
     * Plafond de points par défaut appliqué aux séries préparées.
     *
     * Au-delà, les longues périodes sont sous-échantillonnées (LTTB) afin
     * d'alléger les pages Highcharts (cf. docs/AUDIT_GRAPHIQUES_HIGHCHARTS.md §5).
     * En dessous, le comportement est strictement identique à l'historique.
     */
    public const DEFAULT_MAX_POINTS = SeriesDownsampler::DEFAULT_MAX_POINTS;

    /**
     * Colonne de référence (axe Y LTTB) pour décimer les lectures FFP3.
     * Le niveau d'eau aquarium est la série « phare » de la page aquaponie.
     */
    private const FFP3_REFERENCE_COLUMN = 'EauAquarium';

    /**
     * Mémoïsation request-scoped du sous-échantillonnage LTTB.
     *
     * prepareSeriesData() et prepareTimestamps() décimaient chacun le MÊME jeu de
     * lectures avec les mêmes paramètres → LTTB exécuté deux fois par rendu de page.
     * Le cache (clé = colonne de référence + plafond + signature du jeu) fait que le
     * second appel réutilise le résultat du premier. LTTB étant déterministe, la
     * sortie est strictement identique (mêmes index retenus, même ordre).
     *
     * @var array<string, list<array<string, mixed>>>
     */
    private array $downsampleCache = [];

    /**
     * Prépare toutes les séries de données pour Highcharts
     *
     * @param array $readings Lectures capteurs (ordre DESC de la DB)
     * @param int $maxPoints Plafond de points par série (décimation LTTB au-delà)
     * @return array Tableau associatif des séries encodées en JSON
     */
    public function prepareSeriesData(array $readings, int $maxPoints = self::DEFAULT_MAX_POINTS): array
    {
        // Décimation au niveau des lectures : toutes les colonnes (et les
        // timestamps préparés ensuite à partir du même jeu) restent alignées.
        // Mémoïsé : prepareTimestamps() réutilise ce même résultat (pas de 2e LTTB).
        $readings = $this->downsampleReadingsMemoized($readings, self::FFP3_REFERENCE_COLUMN, $maxPoints);

        // Utilitaires internes (JSON injecte dans bloc <script> Twig via |raw : on durcit
        // l'echappement avec JSON_HEX_TAG/HEX_AMP/HEX_QUOT/HEX_APOS pour eviter toute
        // rupture du contexte JS si une valeur (cas peu probable, mais defense en profondeur)
        // contenait un caractere actif).
        $col = static fn (array $rows, string $key): array => array_column($rows, $key);
        $encode = static fn (array $values): string => json_encode(
            array_reverse($values),
            JSON_NUMERIC_CHECK | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS
        );

        // Séries pour Highcharts (ordre chronologique inversé comme legacy)
        return [
            'EauAquarium' => $encode($col($readings, 'EauAquarium')),
            'EauReserve' => $encode($col($readings, 'EauReserve')),
            'EauPotager' => $encode($col($readings, 'EauPotager')),
            'TempAir' => $encode($col($readings, 'TempAir')),
            'TempEau' => $encode($col($readings, 'TempEau')),
            'Humidite' => $encode($col($readings, 'Humidite')),
            'Luminosite' => $encode($col($readings, 'Luminosite')),
            'etatPompeAqua' => $encode($col($readings, 'etatPompeAqua')),
            'etatPompeTank' => $encode($col($readings, 'etatPompeTank')),
            'etatHeat' => $encode($col($readings, 'etatHeat')),
            'etatUV' => $encode($col($readings, 'etatUV')),
            'bouffePetits' => $encode($col($readings, 'bouffePetits')),
            'bouffeGros' => $encode($col($readings, 'bouffeGros')),
        ];
    }

    /**
     * Prépare les timestamps pour Highcharts (ms epoch UTC)
     *
     * @param array $readings Lectures capteurs (ordre DESC de la DB)
     * @param int $maxPoints Plafond de points (décimation LTTB au-delà)
     * @return string JSON array des timestamps en millisecondes
     */
    public function prepareTimestamps(array $readings, int $maxPoints = self::DEFAULT_MAX_POINTS): string
    {
        // Même décimation que prepareSeriesData : index sélectionnés identiques
        // (même jeu de lectures, même colonne de référence, même plafond) donc
        // timestamps et valeurs restent strictement cohérents. Servi depuis le
        // cache quand prepareSeriesData() a déjà décimé le même jeu.
        $readings = $this->downsampleReadingsMemoized($readings, self::FFP3_REFERENCE_COLUMN, $maxPoints);

        $col = static fn (array $rows, string $key): array => array_column($rows, $key);

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
     * @param int $maxPoints Plafond de points par série (décimation LTTB au-delà)
     * @return array<string, mixed> reading_time[] + une clé par colonne
     */
    public function prepareGenericSeries(
        array $readings,
        array $columns,
        int $maxPoints = self::DEFAULT_MAX_POINTS
    ): array {
        // Décimation au niveau des lectures : toutes les colonnes restent
        // alignées avec reading_time (mêmes index retenus).
        $referenceColumn = $columns[0] ?? 'reading_time';
        $readings = $this->downsampleReadingsMemoized($readings, $referenceColumn, $maxPoints);

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
     * Sous-échantillonne les lectures avec mémoïsation request-scoped.
     *
     * La clé combine colonne de référence, plafond et une signature légère du jeu
     * (nombre de lignes + 1er/dernier reading_time). Dans le flux nominal d'une page,
     * prepareSeriesData() et prepareTimestamps() reçoivent le MÊME `$readings` : le
     * second appel est donc un simple hit de cache (une seule passe LTTB).
     *
     * @param array<int|string, array<string, mixed>> $readings
     * @return list<array<string, mixed>>
     */
    private function downsampleReadingsMemoized(array $readings, string $referenceColumn, int $maxPoints): array
    {
        $readings = array_values($readings);
        $count = count($readings);
        $first = $count > 0 ? (string) ($readings[0]['reading_time'] ?? '') : '';
        $last = $count > 0 ? (string) ($readings[$count - 1]['reading_time'] ?? '') : '';
        $key = $referenceColumn . '|' . $maxPoints . '|' . $count . '|' . $first . '|' . $last;

        if (!array_key_exists($key, $this->downsampleCache)) {
            $this->downsampleCache[$key] = SeriesDownsampler::downsampleReadings(
                $readings,
                'reading_time',
                $referenceColumn,
                $maxPoints
            );
        }

        return $this->downsampleCache[$key];
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
                'tempair' => 0,
                'tempeau' => 0,
                'humi' => 0,
                'lumi' => 0,
                'eauaqua' => 0,
                'eaureserve' => 0,
                'eaupota' => 0,
                'time' => date('Y-m-d H:i:s'),
            ];
        }

        return [
            'tempair' => $lastReading['TempAir'] ?? 0,
            'tempeau' => $lastReading['TempEau'] ?? 0,
            'humi' => $lastReading['Humidite'] ?? 0,
            'lumi' => $lastReading['Luminosite'] ?? 0,
            'eauaqua' => (isset($lastReading['EauAquarium']) && $lastReading['EauAquarium'] !== '')
                ? (float) $lastReading['EauAquarium'] : null,
            'eaureserve' => (isset($lastReading['EauReserve']) && $lastReading['EauReserve'] !== '')
                ? (float) $lastReading['EauReserve'] : null,
            'eaupota' => (isset($lastReading['EauPotager']) && $lastReading['EauPotager'] !== '')
                ? (float) $lastReading['EauPotager'] : null,
            'time' => $lastReading['reading_time'] ?? date('Y-m-d H:i:s'),
        ];
    }
}

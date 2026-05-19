<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\TableConfig;
use DateTimeInterface;
use PDO;

/**
 * Service de calculs statistiques sur les mesures capteurs.
 * Permet d'obtenir min, max, moyenne, écart-type sur des périodes données.
 * Valide les colonnes autorisées pour éviter les erreurs ou injections.
 */
class SensorStatisticsService
{
    /**
     * Colonnes numériques autorisées pour les agrégats statistiques
     */
    private const NUMERIC_COLUMNS = [
        'TempAir', 'Humidite', 'TempEau', 'EauPotager', 'EauAquarium', 'EauReserve', 'Luminosite',
    ];

    /**
     * @param PDO $pdo Connexion PDO à la base de données (injectée)
     */
    public function __construct(private PDO $pdo) {}

    /**
     * Calcule le minimum d'une colonne sur une période donnée.
     * @return float|null
     */
    public function min(string $column, DateTimeInterface|string $start, DateTimeInterface|string $end): ?float
    {
        return $this->aggregate('MIN', $column, $start, $end);
    }

    /**
     * Calcule le maximum d'une colonne sur une période donnée.
     * @return float|null
     */
    public function max(string $column, DateTimeInterface|string $start, DateTimeInterface|string $end): ?float
    {
        return $this->aggregate('MAX', $column, $start, $end);
    }

    /**
     * Calcule la moyenne d'une colonne sur une période donnée.
     * @return float|null
     */
    public function avg(string $column, DateTimeInterface|string $start, DateTimeInterface|string $end): ?float
    {
        return $this->aggregate('AVG', $column, $start, $end);
    }

    /**
     * Calcule l'écart-type d'une colonne sur une période donnée.
     * @return float|null
     */
    public function stddev(string $column, DateTimeInterface|string $start, DateTimeInterface|string $end): ?float
    {
        return $this->aggregate('STDDEV', $column, $start, $end);
    }

    /**
     * Méthode générique pour calculer un agrégat SQL (min, max, avg, stddev).
     * Valide la colonne, convertit les dates, exécute la requête et retourne le résultat.
     *
     * @param string $func   Fonction SQL ('MIN', 'MAX', 'AVG', 'STDDEV')
     * @param string $column Colonne à agréger (doit être dans NUMERIC_COLUMNS)
     * @param DateTimeInterface|string $start Début période
     * @param DateTimeInterface|string $end   Fin période
     * @return float|null
     */
    private function aggregate(string $func, string $column, DateTimeInterface|string $start, DateTimeInterface|string $end): ?float
    {
        // Validation de la colonne pour éviter toute injection ou erreur
        if (!in_array($column, self::NUMERIC_COLUMNS, true)) {
            throw new \InvalidArgumentException("Colonne non autorisée : $column");
        }

        // Conversion des dates en string SQL si besoin
        if ($start instanceof DateTimeInterface) {
            $start = $start->format('Y-m-d H:i:s');
        }
        if ($end instanceof DateTimeInterface) {
            $end = $end->format('Y-m-d H:i:s');
        }

        $table = TableConfig::getDataTable();
        $sql  = sprintf('SELECT %s(%s) AS val FROM %s WHERE reading_time BETWEEN :start AND :end', $func, $column, $table);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':start' => $start, ':end' => $end]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row['val'] !== null ? (float)$row['val'] : null;
    }

    /**
     * Calcule MIN/MAX/AVG/STDDEV de plusieurs colonnes en **une seule requête**.
     *
     * Remplace 4N requêtes par 1 (cf. `aggregateAllStats()` qui passait par
     * 4 appels × 7 colonnes = 28 requêtes pour la page Aquaponie).
     *
     * @param string[] $columns Colonnes à agréger (filtrées par NUMERIC_COLUMNS).
     * @param DateTimeInterface|string $start
     * @param DateTimeInterface|string $end
     * @return array<string, array{min: float|null, max: float|null, avg: float|null, stddev: float|null}>
     */
    public function aggregateMany(array $columns, DateTimeInterface|string $start, DateTimeInterface|string $end): array
    {
        $columns = array_values(array_filter(
            $columns,
            static fn($c): bool => in_array($c, self::NUMERIC_COLUMNS, true)
        ));
        if ($columns === []) {
            return [];
        }

        if ($start instanceof DateTimeInterface) {
            $start = $start->format('Y-m-d H:i:s');
        }
        if ($end instanceof DateTimeInterface) {
            $end = $end->format('Y-m-d H:i:s');
        }

        // Construction d'un SELECT unique avec MIN/MAX/AVG/STDDEV par colonne.
        // Les noms de colonnes proviennent d'une whitelist (NUMERIC_COLUMNS) — pas d'injection possible.
        $selects = [];
        foreach ($columns as $col) {
            $selects[] = sprintf('MIN(%1$s) AS min_%1$s', $col);
            $selects[] = sprintf('MAX(%1$s) AS max_%1$s', $col);
            $selects[] = sprintf('AVG(%1$s) AS avg_%1$s', $col);
            $selects[] = sprintf('STDDEV(%1$s) AS stddev_%1$s', $col);
        }
        $table = TableConfig::getDataTable();
        $sql = 'SELECT ' . implode(', ', $selects)
            . sprintf(' FROM %s WHERE reading_time BETWEEN :start AND :end', $table);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':start' => $start, ':end' => $end]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $result = [];
        foreach ($columns as $col) {
            $result[$col] = [
                'min'    => isset($row["min_{$col}"])    ? (float) $row["min_{$col}"]    : null,
                'max'    => isset($row["max_{$col}"])    ? (float) $row["max_{$col}"]    : null,
                'avg'    => isset($row["avg_{$col}"])    ? (float) $row["avg_{$col}"]    : null,
                'stddev' => isset($row["stddev_{$col}"]) ? (float) $row["stddev_{$col}"] : null,
            ];
            // Conserver null si la BDD a retourné NULL (pas de donnees)
            if ($row["min_{$col}"] === null)    $result[$col]['min']    = null;
            if ($row["max_{$col}"] === null)    $result[$col]['max']    = null;
            if ($row["avg_{$col}"] === null)    $result[$col]['avg']    = null;
            if ($row["stddev_{$col}"] === null) $result[$col]['stddev'] = null;
        }
        return $result;
    }

    /**
     * Calcule l'écart-type des N dernières mesures pour une colonne donnée.
     * (À compléter selon les besoins du projet)
     */
    public function stddevOnLastReadings(string $column, int $limit = 60): ?float
    {
        // Validation de la colonne
        if (!in_array($column, self::NUMERIC_COLUMNS, true)) {
            throw new \InvalidArgumentException("Colonne non autorisée : $column");
        }

        // La sous-requête sélectionne les N dernières valeurs, la requête externe calcule STDDEV
        $limit = (int)$limit;
        if ($limit < 1) {
            $limit = 1;
        }
        
        $table = TableConfig::getDataTable();
        $sql = <<<SQL
            SELECT STDDEV(sub.$column) as stddev_amount
            FROM (
                SELECT $column
                FROM {$table}
                ORDER BY reading_time DESC
                LIMIT $limit
            ) as sub
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result && $result['stddev_amount'] !== null ? (float)$result['stddev_amount'] : null;
    }
}

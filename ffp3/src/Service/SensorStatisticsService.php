<?php

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

<?php

namespace App\Service;

use DateTimeInterface;
use PDO;

class SensorStatisticsService
{
    /** Colonnes numériques autorisées pour les agrégats */
    private const NUMERIC_COLUMNS = [
        'TempAir', 'Humidite', 'TempEau', 'EauPotager', 'EauAquarium', 'EauReserve', 'Luminosite',
    ];

    public function __construct(private PDO $pdo) {}

    public function min(string $column, DateTimeInterface|string $start, DateTimeInterface|string $end): ?float
    {
        return $this->aggregate('MIN', $column, $start, $end);
    }

    public function max(string $column, DateTimeInterface|string $start, DateTimeInterface|string $end): ?float
    {
        return $this->aggregate('MAX', $column, $start, $end);
    }

    public function avg(string $column, DateTimeInterface|string $start, DateTimeInterface|string $end): ?float
    {
        return $this->aggregate('AVG', $column, $start, $end);
    }

    public function stddev(string $column, DateTimeInterface|string $start, DateTimeInterface|string $end): ?float
    {
        return $this->aggregate('STDDEV', $column, $start, $end);
    }

    private function aggregate(string $func, string $column, DateTimeInterface|string $start, DateTimeInterface|string $end): ?float
    {
        if (!in_array($column, self::NUMERIC_COLUMNS, true)) {
            throw new \InvalidArgumentException("Colonne non autorisée : $column");
        }

        if ($start instanceof DateTimeInterface) {
            $start = $start->format('Y-m-d H:i:s');
        }
        if ($end instanceof DateTimeInterface) {
            $end = $end->format('Y-m-d H:i:s');
        }

        $sql  = sprintf('SELECT %s(%s) AS val FROM ffp3Data WHERE reading_time BETWEEN :start AND :end', $func, $column);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':start' => $start, ':end' => $end]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row['val'] !== null ? (float)$row['val'] : null;
    }

    /**
     * Calcule l'écart-type des N dernières mesures pour une colonne donnée.
     */
    public function stddevOnLastReadings(string $column, int $limit = 60): ?float
    {
        // La sous-requête sélectionne les N dernières valeurs, la requête externe calcule STDDEV
        $sql = <<<SQL
            SELECT STDDEV(sub.$column) as stddev_amount
            FROM (
                SELECT $column
                FROM ffp3Data
                ORDER BY reading_time DESC
                LIMIT :limit
            ) as sub
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':limit' => $limit]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result && $result['stddev_amount'] !== null ? (float)$result['stddev_amount'] : null;
    }
} 
<?php

namespace App\Repository;

use DateTimeInterface;
use PDO;

class SensorReadRepository
{
    public function __construct(private PDO $pdo) {}

    /**
     * Récupère les enregistrements entre deux dates (incluses)
     * @param DateTimeInterface|string $start
     * @param DateTimeInterface|string $end
     * @return array<array<string, mixed>>
     */
    public function fetchBetween(DateTimeInterface|string $start, DateTimeInterface|string $end): array
    {
        if ($start instanceof DateTimeInterface) {
            $start = $start->format('Y-m-d H:i:s');
        }
        if ($end instanceof DateTimeInterface) {
            $end = $end->format('Y-m-d H:i:s');
        }

        $sql = <<<'SQL'
            SELECT id, TempAir, Humidite, TempEau, EauPotager, EauAquarium, EauReserve, Luminosite,
                   etatPompeAqua, etatPompeTank, etatHeat, etatUV, bouffePetits, bouffeGros, reading_time
            FROM ffp3Data
            WHERE reading_time BETWEEN :start AND :end
            ORDER BY reading_time DESC
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':start' => $start,
            ':end'   => $end,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retourne la date/heure de la dernière mesure ou null.
     */
    public function getLastReadingDate(): ?string
    {
        $sql   = 'SELECT MAX(reading_time) AS last_date FROM ffp3Data';
        $stmt  = $this->pdo->query($sql);
        $value = $stmt->fetch(PDO::FETCH_ASSOC);

        return $value["last_date"] ?? null;
    }

    /**
     * Exporte les données dans un fichier CSV (chemin fourni) et retourne le nombre de lignes écrites.
     * @param DateTimeInterface|string $start
     * @param DateTimeInterface|string $end
     * @param string $filePath
     */
    public function exportCsv(DateTimeInterface|string $start, DateTimeInterface|string $end, string $filePath): int
    {
        $rows = $this->fetchBetween($start, $end);
        if ($rows === []) {
            return 0;
        }

        $handle = fopen($filePath, 'w');
        if ($handle === false) {
            throw new \RuntimeException('Impossible d\'ouvrir le fichier ' . $filePath);
        }

        // En-têtes
        fputcsv($handle, array_keys($rows[0]));
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);

        return count($rows);
    }
} 
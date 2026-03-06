<?php

declare(strict_types=1);

namespace App\Repository;

use App\Config\TableConfig;
use App\Util\TableValidator;
use DateTimeInterface;
use PDO;

/**
 * Repository dédié à la lecture des données capteurs depuis la base de données.
 * Permet de centraliser les requêtes de récupération, d'export et d'analyse des mesures.
 */
class SensorReadRepository extends AbstractRepository
{
    /**
     * Récupère tous les enregistrements de mesures entre deux dates (incluses).
     *
     * @param DateTimeInterface|string $start Date/heure de début (objet ou string SQL)
     * @param DateTimeInterface|string $end   Date/heure de fin (objet ou string SQL)
     * @return array<array<string, mixed>>    Tableau associatif de mesures (une par ligne)
     *
     * Cette méthode convertit les objets DateTime en string SQL si besoin, puis exécute
     * une requête préparée pour récupérer les mesures dans l'intervalle demandé.
     */
    public function fetchBetween(DateTimeInterface|string $start, DateTimeInterface|string $end): array
    {
        // Conversion des dates en string SQL si besoin
        if ($start instanceof DateTimeInterface) {
            $start = $start->format('Y-m-d H:i:s');
        }
        if ($end instanceof DateTimeInterface) {
            $end = $end->format('Y-m-d H:i:s');
        }

        // Requête SQL multi-colonnes, triée par date décroissante
        $table = TableConfig::getDataTable();
        $sql = <<<SQL
            SELECT id, TempAir, Humidite, TempEau, EauPotager, EauAquarium, EauReserve, diffMaree, Luminosite,
                   etatPompeAqua, etatPompeTank, etatHeat, etatUV, bouffePetits, bouffeGros, reading_time
            FROM {$table}
            WHERE reading_time BETWEEN :start AND :end
            ORDER BY reading_time DESC
        SQL;

        return $this->fetchAll($sql, [':start' => $start, ':end' => $end]);
    }

    /**
     * Retourne la date/heure de la dernière mesure enregistrée, ou null si aucune donnée.
     *
     * @return string|null Date/heure SQL de la dernière mesure, ou null
     */
    public function getLastReadingDate(): ?string
    {
        $table = TableValidator::validateDataTable(TableConfig::getDataTable());
        $sql = "SELECT MAX(reading_time) AS last_date FROM `{$table}`";
        
        $result = $this->fetchOne($sql);
        return $result['last_date'] ?? null;
    }

    /**
     * Exporte les données dans un fichier CSV (chemin fourni) et retourne le nombre de lignes écrites.
     *
     * @param DateTimeInterface|string $start Date/heure de début
     * @param DateTimeInterface|string $end   Date/heure de fin
     * @param string $filePath                Chemin du fichier CSV à créer
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

    /**
     * Récupère la ou les dernières lectures enregistrées.
     * @param int $limit Nombre de lignes à remonter (1 par défaut).
     *                  Si $limit vaut 1, un tableau associatif représentant la ligne est renvoyé.
     *                  Sinon, un tableau de tableaux est retourné.
     * @return array<string, mixed>|array<int, array<string, mixed>> Tableau(x) des dernières lectures.
     */
    public function getLastReadings(int $limit = 1): array
    {
        $table = TableConfig::getDataTable();
        $sql = "SELECT * FROM {$table} ORDER BY reading_time DESC LIMIT :limit";

        $stmt = $this->pdo->prepare($sql);
        // PARAM_INT garantit l'utilisation d'un entier (pas d'injection SQL possible)
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        if ($limit === 1) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row !== false ? $row : [];
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Récupère toutes les lectures depuis une date donnée
     *
     * @param string $sinceDate Date au format 'Y-m-d H:i:s'
     * @return array<array<string, mixed>> Tableau de lectures
     */
    public function getReadingsSince(string $sinceDate): array
    {
        $table = TableConfig::getDataTable();
        $sql = <<<SQL
            SELECT id, TempAir, Humidite, TempEau, EauPotager, EauAquarium, EauReserve, diffMaree, Luminosite,
                   etatPompeAqua, etatPompeTank, etatHeat, etatUV, bouffePetits, bouffeGros, reading_time
            FROM {$table}
            WHERE reading_time > :since_date
            ORDER BY reading_time ASC
        SQL;

        return $this->fetchAll($sql, [':since_date' => $sinceDate]);
    }

    /**
     * Compte le nombre de lectures entre deux dates
     *
     * @param string $start Date de début au format 'Y-m-d H:i:s'
     * @param string $end Date de fin au format 'Y-m-d H:i:s'
     * @return int Nombre de lectures
     */
    public function countReadingsBetween(string $start, string $end): int
    {
        $table = TableConfig::getDataTable();
        $sql = "SELECT COUNT(*) as count FROM {$table} WHERE reading_time BETWEEN :start AND :end";

        $result = $this->fetchOne($sql, [':start' => $start, ':end' => $end]);
        return (int)($result['count'] ?? 0);
    }

    /**
     * Récupère la version du firmware de la dernière mesure enregistrée
     *
     * @return string Version du firmware (ex: "10.90") ou "N/A" si aucune donnée
     */
    public function getFirmwareVersion(): string
    {
        $table = TableValidator::validateDataTable(TableConfig::getDataTable());
        $sql = "SELECT version FROM `{$table}` ORDER BY reading_time DESC LIMIT 1";
        
        $result = $this->fetchOne($sql);
        return $result['version'] ?? 'N/A';
    }
}

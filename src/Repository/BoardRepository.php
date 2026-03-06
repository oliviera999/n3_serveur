<?php

declare(strict_types=1);

namespace App\Repository;

use App\Util\TableValidator;
use PDO;

/**
 * Repository pour gérer les boards (cartes ESP32) en base de données
 * 
 * Note: La table Boards est partagée entre PROD et TEST (pas de Boards2)
 */
class BoardRepository extends AbstractRepository
{
    /**
     * Récupère uniquement les boards actives pour un environnement donné
     * Une board est considérée active si elle a des outputs dans la table correspondante
     * 
     * @param string $outputsTable Nom de la table outputs (ffp3Outputs ou ffp3Outputs2)
     * @return array<int, array<string, mixed>>
     */
    public function findActiveForEnvironment(string $outputsTable): array
    {
        // Valider le nom de table pour sécurité
        TableValidator::validateOutputsTable($outputsTable);
        
        $sql = "SELECT DISTINCT b.board, b.last_request
                FROM Boards b
                INNER JOIN `{$outputsTable}` o ON b.board = o.board
                WHERE o.name IS NOT NULL AND o.name != ''
                ORDER BY b.board ASC";

        $rows = $this->fetchAll($sql);

        foreach ($rows as &$row) {
            $row['last_request'] = $this->formatTimestamp($row['last_request']);
        }

        return $rows;
    }

    /**
     * Récupère une board spécifique par son nom
     * 
     * @param string $board Nom de la board
     * @return array<string, mixed>|null
     */
    public function findByName(string $board): ?array
    {
        $sql = "SELECT board, last_request FROM Boards WHERE board = :board";

        $result = $this->fetchOne($sql, [':board' => $board]);
        if ($result === null) {
            return null;
        }

        $result['last_request'] = $this->formatTimestamp($result['last_request']);

        return $result;
    }

    /**
     * Met à jour la dernière requête d'une board
     * Convention : heure serveur (NOW()), alignée avec Outputs.requestTime et APP_TIMEZONE.
     * Voir docs/TIMEZONE_MANAGEMENT.md.
     *
     * @param string $board Nom de la board
     * @return bool Succès de l'opération
     */
    public function updateLastRequest(string $board): bool
    {
        $sql = "UPDATE Boards SET last_request = NOW() WHERE board = :board";
        
        return $this->execute($sql, [':board' => $board]);
    }

    /**
     * Crée une nouvelle board
     * 
     * @param string $board Nom de la board
     * @return bool Succès de l'opération
     */
    public function create(string $board): bool
    {
        $sql = "INSERT INTO Boards (board) VALUES (:board)";
        
        return $this->execute($sql, [':board' => $board]);
    }

    /**
     * Vérifie si une board existe
     * 
     * @param string $board Nom de la board
     * @return bool
     */
    public function exists(string $board): bool
    {
        return $this->findByName($board) !== null;
    }

    /**
     * Formate un timestamp (heure serveur, APP_TIMEZONE) en date lisible
     * last_request est stocké avec NOW() donc déjà en heure serveur.
     *
     * @param string|null $timestamp Timestamp en heure serveur (APP_TIMEZONE)
     * @return string|null Timestamp formaté ou null
     */
    private function formatTimestamp(?string $timestamp): ?string
    {
        if ($timestamp === null) {
            return null;
        }

        try {
            $tz = new \DateTimeZone($_ENV['APP_TIMEZONE'] ?? 'Europe/Paris');
            return (new \DateTimeImmutable($timestamp, $tz))->format('d/m/Y H:i:s');
        } catch (\Exception $e) {
            return $timestamp;
        }
    }
}

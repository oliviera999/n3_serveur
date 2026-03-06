<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Classe abstraite de base pour les repositories.
 * 
 * Fournit des méthodes utilitaires pour les opérations PDO courantes,
 * réduisant la duplication de code entre les repositories.
 */
abstract class AbstractRepository
{
    /**
     * @param PDO $pdo Connexion PDO à la base de données (injectée)
     */
    public function __construct(protected PDO $pdo) {}

    /**
     * Exécute une requête SELECT et retourne toutes les lignes
     *
     * @param string $sql Requête SQL
     * @param array $params Paramètres de la requête
     * @return array<int, array<string, mixed>> Tableau de résultats
     */
    protected function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Exécute une requête SELECT et retourne une seule ligne
     *
     * @param string $sql Requête SQL
     * @param array $params Paramètres de la requête
     * @return array<string, mixed>|null Résultat ou null si non trouvé
     */
    protected function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result === false ? null : $result;
    }

    /**
     * Exécute une requête SELECT et retourne une valeur scalaire
     *
     * @param string $sql Requête SQL
     * @param array $params Paramètres de la requête
     * @return mixed Valeur scalaire ou null
     */
    protected function fetchScalar(string $sql, array $params = []): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_NUM);
        return $result === false ? null : $result[0];
    }

    /**
     * Exécute une requête INSERT/UPDATE/DELETE
     *
     * @param string $sql Requête SQL
     * @param array $params Paramètres de la requête
     * @return bool Succès de l'opération
     */
    protected function execute(string $sql, array $params = []): bool
    {
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Exécute une requête et retourne le nombre de lignes affectées
     *
     * @param string $sql Requête SQL
     * @param array $params Paramètres de la requête
     * @return int Nombre de lignes affectées
     */
    protected function executeWithRowCount(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Exécute un callback dans une transaction
     *
     * @param callable $callback Fonction à exécuter dans la transaction
     * @return mixed Valeur de retour du callback
     * @throws \Exception En cas d'erreur, la transaction est annulée et l'exception est relancée
     */
    protected function executeInTransaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $callback();
            $this->pdo->commit();
            return $result;
        } catch (\Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Retourne le dernier ID inséré
     *
     * @return string|false
     */
    protected function lastInsertId(): string|false
    {
        return $this->pdo->lastInsertId();
    }
}

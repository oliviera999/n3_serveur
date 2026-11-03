<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Persistance de la mémoire du connecteur MCP (table `mcp_memory`,
 * migrations/2026_11_mcp_exploitation.sql).
 *
 * Une note est identifiée par (scope, owner_key, subject) : `owner_key` vaut 0 pour une
 * note de site et l'identifiant de l'utilisateur pour une note privée. Les dates sont
 * produites côté PHP (`Y-m-d H:i:s`, fuseau applicatif) pour rester portables (MariaDB en
 * production, SQLite en tests unitaires). Aucune requête n'interpole de valeur fournie
 * par l'appelant.
 */
class McpMemoryRepository extends AbstractRepository
{
    public const SCOPE_SITE = 'site';
    public const SCOPE_USER = 'user';

    private const COLUMNS = 'id, scope, user_id, subject, content, tags, created_by, created_at, updated_at, verified_at';

    /**
     * @return array<string, mixed>|null
     */
    public function findBySubject(string $scope, ?int $userId, string $subject): ?array
    {
        return $this->fetchOne(
            'SELECT ' . self::COLUMNS . ' FROM mcp_memory WHERE scope = :scope AND owner_key = :owner AND subject = :subject LIMIT 1',
            [':scope' => $scope, ':owner' => self::ownerKey($scope, $userId), ':subject' => $subject]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->fetchOne('SELECT ' . self::COLUMNS . ' FROM mcp_memory WHERE id = :id LIMIT 1', [':id' => $id]);
    }

    public function countForOwner(string $scope, ?int $userId): int
    {
        return (int) $this->fetchScalar(
            'SELECT COUNT(*) FROM mcp_memory WHERE scope = :scope AND owner_key = :owner',
            [':scope' => $scope, ':owner' => self::ownerKey($scope, $userId)]
        );
    }

    public function insert(string $scope, ?int $userId, string $subject, string $content, string $tags, int $createdBy): int
    {
        $now = self::now();
        $this->execute(
            'INSERT INTO mcp_memory (scope, user_id, owner_key, subject, content, tags, created_by, created_at, updated_at, verified_at)'
            . ' VALUES (:scope, :user, :owner, :subject, :content, :tags, :by, :c, :u, :v)',
            [
                ':scope' => $scope,
                ':user' => $scope === self::SCOPE_USER ? $userId : null,
                ':owner' => self::ownerKey($scope, $userId),
                ':subject' => $subject,
                ':content' => $content,
                ':tags' => $tags,
                ':by' => $createdBy,
                ':c' => $now,
                ':u' => $now,
                ':v' => $now,
            ]
        );

        return (int) $this->lastInsertId();
    }

    /**
     * Met à jour le contenu et les étiquettes d'une note et rafraîchit `verified_at`.
     */
    public function update(int $id, string $content, string $tags): void
    {
        $now = self::now();
        $this->execute(
            'UPDATE mcp_memory SET content = :content, tags = :tags, updated_at = :u, verified_at = :v WHERE id = :id',
            [':content' => $content, ':tags' => $tags, ':u' => $now, ':v' => $now, ':id' => $id]
        );
    }

    public function delete(int $id): bool
    {
        return $this->executeWithRowCount('DELETE FROM mcp_memory WHERE id = :id', [':id' => $id]) > 0;
    }

    /**
     * Notes visibles par un utilisateur (site + ses notes privées), filtrées.
     *
     * Les jokers LIKE (`%`, `_`) éventuellement saisis ne font qu'élargir la recherche dans
     * les notes déjà visibles (requête paramétrée, aucun risque d'injection).
     *
     * Chaque terme de `$query` doit apparaître (sans casse) dans le sujet, le contenu ou les
     * étiquettes ; chaque étiquette de `$tags` doit être portée par la note. Tri : sujet
     * exactement égal d'abord, puis note la plus récemment vérifiée.
     *
     * @param list<string> $terms
     * @param list<string> $tags
     * @return list<array<string, mixed>>
     */
    public function search(int $userId, array $terms, array $tags, ?string $scope, int $limit): array
    {
        $limit = max(1, min(100, $limit));
        $where = ["(scope = 'site' OR (scope = 'user' AND owner_key = :owner))"];
        $params = [':owner' => $userId];
        if ($scope !== null) {
            $where[] = 'scope = :scope';
            $params[':scope'] = $scope;
        }
        foreach (array_values($terms) as $i => $term) {
            $key = ':t' . $i;
            $where[] = "(LOWER(subject) LIKE {$key} OR LOWER(content) LIKE {$key} OR LOWER(tags) LIKE {$key})";
            $params[$key] = '%' . mb_strtolower($term) . '%';
        }
        foreach (array_values($tags) as $i => $tag) {
            $key = ':g' . $i;
            $where[] = "(',' || REPLACE(tags, ' ', '') || ',') LIKE {$key}";
            $params[$key] = '%,' . $tag . ',%';
        }

        $sql = 'SELECT ' . self::COLUMNS . ' FROM mcp_memory WHERE ' . implode(' AND ', $where)
            . " ORDER BY verified_at DESC, id DESC LIMIT {$limit}";

        return $this->fetchAll($this->portable($sql), $params);
    }

    /**
     * Concaténation portable : `||` est la concaténation SQL standard (SQLite), MySQL/MariaDB
     * l'interprètent comme un OU logique sauf en mode PIPES_AS_CONCAT → CONCAT().
     */
    private function portable(string $sql): string
    {
        if ((string) $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            return $sql;
        }

        return str_replace(
            "(',' || REPLACE(tags, ' ', '') || ',')",
            "CONCAT(',', REPLACE(tags, ' ', ''), ',')",
            $sql
        );
    }

    private static function ownerKey(string $scope, ?int $userId): int
    {
        return $scope === self::SCOPE_USER ? (int) $userId : 0;
    }

    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}

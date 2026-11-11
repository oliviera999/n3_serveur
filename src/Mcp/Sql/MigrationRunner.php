<?php

declare(strict_types=1);

namespace App\Mcp\Sql;

use PDO;

/**
 * Exécution des migrations ADDITIVES du dépôt (`migrations/*.sql`), partagée par l'outil MCP
 * `iot_migrate` et la commande CRON {@see \App\Command\AutoMigrateCommand}.
 *
 * Aucun SQL fourni par l'appelant : seules les instructions d'un fichier du dépôt reconnu additif par
 * {@see MigrationFile} (ADD COLUMN / ADD INDEX / CREATE TABLE / CREATE INDEX) sont exécutées, une par
 * une, chacune ré-évaluée contre le schéma réel juste avant (déjà en place ou table absente → sautée).
 * Rejouer est donc sans effet. Arrêt au premier échec (les suivantes ne sont pas tentées).
 */
final class MigrationRunner
{
    public function __construct(
        private PDO $pdo,
        private SchemaReader $schema,
        private MigrationCatalog $catalog,
    ) {
    }

    public function catalog(): MigrationCatalog
    {
        return $this->catalog;
    }

    /**
     * Fichiers additifs ayant au moins une instruction en attente (pending / partial), tri alphabétique.
     *
     * @return list<string>
     */
    public function pendingFiles(): array
    {
        $this->schema->forget();
        $out = [];
        foreach ($this->catalog->files() as $file) {
            try {
                $inspected = $this->catalog->inspect($file);
            } catch (\Throwable) {
                continue;
            }
            if ($inspected['additive_only']
                && in_array($inspected['status'], [MigrationCatalog::ST_PENDING, MigrationCatalog::ST_PARTIAL], true)) {
                $out[] = $file;
            }
        }

        return $out;
    }

    /**
     * Instructions en attente d'un fichier inspecté.
     *
     * @param array{statements: list<array<string, mixed>>} $inspected
     * @return list<array<string, mixed>>
     */
    public static function runnable(array $inspected): array
    {
        return array_values(array_filter(
            $inspected['statements'],
            static fn (array $s): bool => in_array($s['status'], [MigrationCatalog::ST_PENDING, MigrationCatalog::ST_PARTIAL], true)
        ));
    }

    /**
     * Raison empêchant l'application automatique (null = applicable) : migration non additive ou
     * instruction partiellement en place sans « IF NOT EXISTS » (la rejouer échouerait).
     *
     * @param array{additive_only: bool, statements: list<array<string, mixed>>} $inspected
     * @return array{code: string, message: string}|null
     */
    public static function blocker(array $inspected): ?array
    {
        if (!$inspected['additive_only']) {
            return ['code' => 'migration_not_additive', 'message' => 'Migration non additive (DROP, UPDATE, DELETE, MODIFY…) : à appliquer via phpMyAdmin.'];
        }
        foreach (self::runnable($inspected) as $s) {
            if ($s['status'] === MigrationCatalog::ST_PARTIAL && !$s['idempotent']) {
                return [
                    'code' => 'migration_partial',
                    'message' => 'Instruction partiellement appliquée sur ' . $s['table'] . ' sans « IF NOT EXISTS » : rejouer échouerait. '
                        . 'Compléter à la main (manquant : ' . implode(', ', (array) $s['missing']) . ').',
                ];
            }
        }

        return null;
    }

    /**
     * Applique les instructions en attente d'un fichier (sans contrôle d'accès : à l'appelant).
     *
     * @return array{file: string, ok: bool, executed: int, results: list<array<string, mixed>>, status_after: string}
     */
    public function apply(string $file): array
    {
        $this->schema->forget();
        $results = [];
        $failed = false;
        foreach ($this->catalog->statements($file) as $raw) {
            [$status] = $this->catalog->statementStatus($raw);
            if (!in_array($status, [MigrationCatalog::ST_PENDING, MigrationCatalog::ST_PARTIAL], true)) {
                $results[] = ['table' => $raw['table'], 'status' => $status, 'executed' => false];
                continue;
            }
            $started = microtime(true);
            try {
                $this->pdo->exec($raw['sql']);
                $this->schema->forget();
                $results[] = ['table' => $raw['table'], 'status' => 'done', 'executed' => true, 'ms' => (int) round((microtime(true) - $started) * 1000)];
            } catch (\Throwable $e) {
                $failed = true;
                $results[] = ['table' => $raw['table'], 'status' => 'error', 'executed' => false, 'error' => mb_substr($e->getMessage(), 0, 300)];
                break;
            }
        }
        $this->schema->forget();

        return [
            'file' => $file,
            'ok' => !$failed,
            'executed' => count(array_filter($results, static fn (array $r): bool => $r['executed'])),
            'results' => $results,
            'status_after' => $this->catalog->inspect($file)['status'],
        ];
    }
}

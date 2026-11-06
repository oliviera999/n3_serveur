<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\McpConfig;
use App\Mcp\McpContext;
use App\Mcp\Sql\MigrationCatalog;
use App\Mcp\Sql\SchemaReader;
use App\Mcp\ToolException;
use App\Mcp\ToolResult;

/**
 * `iot_db_schema` — ADMIN, lecture seule : schéma de la base (tables, colonnes, index) et état des
 * migrations du dépôt (appliquée / en attente / partielle) confronté au schéma réel.
 *
 * Métadonnées uniquement (INFORMATION_SCHEMA) : aucune valeur de ligne n'est lue. Répond à « la
 * migration X est-elle passée en prod ? » sans SQL libre (ex. 2026_11_ffp3_echo_diag.sql, 6.57.0).
 */
final class DbSchemaTool extends AbstractTool
{
    public function __construct(McpConfig $config, private SchemaReader $schema, private MigrationCatalog $migrations)
    {
        parent::__construct($config);
    }

    public function name(): string
    {
        return 'iot_db_schema';
    }

    public function title(): string
    {
        return 'Schéma BDD et migrations (admin)';
    }

    public function description(): string
    {
        return 'ADMIN, lecture seule (métadonnées, aucune valeur de ligne). Sans argument : liste des tables (moteur, '
            . 'lignes estimées, taille). Avec « table » : colonnes (type, NULL, défaut, clé) et index. Avec « migrations »: true : '
            . 'chaque fichier migrations/*.sql du dépôt confronté au schéma réel — applied, pending, partial, table_missing, '
            . 'ou unverifiable (instructions non additives, à passer par phpMyAdmin). « migration » : détail d\'un fichier '
            . '(instructions, éléments manquants). Pour appliquer une migration additive : iot_migrate (si activé).';
    }

    public function inputSchema(): array
    {
        return $this->objectSchema([
            'table' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9_]{1,64}$', 'description' => 'Table à décrire (colonnes + index).'],
            'migrations' => ['type' => 'boolean', 'default' => false, 'description' => 'Lister l\'état de toutes les migrations du dépôt.'],
            'migration' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9][A-Za-z0-9_.-]{0,120}\.sql$', 'description' => 'Détail d\'un fichier migrations/<nom>.sql.'],
        ]);
    }

    public function call(array $arguments, McpContext $context): ToolResult
    {
        AdminGuard::assertAdmin($context);

        if (isset($arguments['migration'])) {
            return ToolResult::json($this->migrations->inspect((string) $arguments['migration']));
        }
        if (!empty($arguments['migrations'])) {
            $list = [];
            $summary = [];
            foreach ($this->migrations->files() as $file) {
                try {
                    $inspected = $this->migrations->inspect($file);
                    $pending = [];
                    foreach ($inspected['statements'] as $s) {
                        if (in_array($s['status'], [MigrationCatalog::ST_PENDING, MigrationCatalog::ST_PARTIAL], true)) {
                            foreach ($s['missing'] as $m) {
                                $pending[] = $s['table'] . '.' . $m;
                            }
                        }
                    }
                    $list[] = [
                        'file' => $file,
                        'status' => $inspected['status'],
                        'additive_only' => $inspected['additive_only'],
                        'statements' => count($inspected['statements']),
                        'missing' => array_slice(array_values(array_unique($pending)), 0, 20),
                    ];
                    $summary[$inspected['status']] = ($summary[$inspected['status']] ?? 0) + 1;
                } catch (ToolException $e) {
                    $list[] = ['file' => $file, 'status' => 'error', 'error' => $e->getMessage()];
                }
            }

            return ToolResult::json([
                'migrations' => $list,
                'summary' => $summary,
                'note' => '« unverifiable » = migration non additive (DROP, UPDATE, DELETE…) ou script de maintenance : '
                    . 'état non déductible du schéma, application hors connecteur. Tables absentes ignorées (table_missing).',
            ]);
        }
        if (isset($arguments['table'])) {
            $table = (string) $arguments['table'];
            if (!$this->schema->tableExists($table)) {
                throw new ToolException('Table inconnue : ' . $table, 'table_unknown');
            }

            return ToolResult::json([
                'table' => $table,
                'columns' => $this->schema->columns($table),
                'indexes' => $this->schema->indexes($table),
            ]);
        }

        $tables = $this->schema->tables();

        return ToolResult::json([
            'tables' => $tables,
            'count' => count($tables),
            'note' => 'rows_estimate = estimation InnoDB (approximative). Détail : « table » ; migrations : « migrations »: true.',
        ]);
    }
}

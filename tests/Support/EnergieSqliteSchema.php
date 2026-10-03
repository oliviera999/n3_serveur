<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Schéma SQLite équivalent à migrations/2026_10_energie_tables.sql (tests unitaires
 * du banc énergie : contrôleur POST, repository).
 */
final class EnergieSqliteSchema
{
    /** Colonnes DOUBLE en MySQL. */
    public const REAL_COLUMNS = [
        'PanneauV', 'PanneauI', 'PanneauP', 'PanneauImax',
        'BatterieV', 'BatterieVmin', 'BatterieI', 'BatterieImin', 'BatterieImax', 'BatterieP', 'BatterieVadc',
        'ConsoV', 'ConsoI', 'ConsoP', 'ConsoImax',
        'EnergiePanneauWh', 'EnergieConsoWh', 'BatterieAh', 'BatterieSoc',
    ];

    /** Colonnes INT / BIGINT en MySQL. */
    public const INT_COLUMNS = ['InaStatus', 'I2cErreurs', 'Rssi', 'FreeHeap', 'BootCount', 'Uptime'];

    public static function createTableSql(string $table): string
    {
        $cols = ['id INTEGER PRIMARY KEY AUTOINCREMENT', 'sensor TEXT', 'version TEXT'];
        foreach (self::REAL_COLUMNS as $c) {
            $cols[] = "{$c} REAL";
        }
        foreach (self::INT_COLUMNS as $c) {
            $cols[] = "{$c} INTEGER";
        }
        $cols[] = 'reading_time TEXT NOT NULL';

        return "CREATE TABLE {$table} (" . implode(', ', $cols) . ')';
    }

    public static function create(\PDO $pdo): void
    {
        foreach (['energieData', 'energieDataTest'] as $table) {
            $pdo->exec(self::createTableSql($table));
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Mcp\Support;

use PDO;

/**
 * Exécute un outil de LECTURE sans aucune possibilité d'écriture persistante.
 *
 * Deux verrous, sur chaque connexion utilisée par les outils :
 *  1. MySQL / MariaDB : `SET SESSION TRANSACTION READ ONLY` — toute écriture (y compris
 *     une instruction en autocommit ou une transaction ouverte par un repository) échoue
 *     avec l'erreur 1792 « Cannot execute statement in a READ ONLY transaction » ;
 *  2. la transaction englobante est TOUJOURS annulée (`ROLLBACK`), jamais validée :
 *     même un moteur sans mode lecture seule (SQLite en test) ne persiste rien.
 *
 * Le mode session est rétabli en lecture/écriture dans un `finally` : la connexion est
 * partagée avec le reste de la requête (jetons OAuth, journal).
 */
final class ReadOnlyTransaction
{
    /** @var list<PDO> */
    private array $connections;

    public function __construct(PDO ...$connections)
    {
        $unique = [];
        foreach ($connections as $pdo) {
            if (!in_array($pdo, $unique, true)) {
                $unique[] = $pdo;
            }
        }
        $this->connections = $unique;
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function run(callable $callback): mixed
    {
        $started = [];
        try {
            foreach ($this->connections as $pdo) {
                if ($pdo->inTransaction()) {
                    continue;
                }
                if (self::isMysql($pdo)) {
                    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
                }
                $pdo->beginTransaction();
                $started[] = $pdo;
            }

            return $callback();
        } finally {
            foreach ($started as $pdo) {
                try {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                } catch (\Throwable) {
                    // Rien à annuler : on continue la remise en état.
                }
                if (self::isMysql($pdo)) {
                    try {
                        $pdo->exec('SET SESSION TRANSACTION READ WRITE');
                    } catch (\Throwable) {
                    }
                }
            }
        }
    }

    private static function isMysql(PDO $pdo): bool
    {
        try {
            return (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        } catch (\Throwable) {
            return false;
        }
    }
}

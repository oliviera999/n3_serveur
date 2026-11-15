<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Reconnaît l'erreur SQL « table inexistante » — et elle SEULE.
 *
 * MySQL / MariaDB : SQLSTATE 42S02, code natif 1146 (« Table 'x' doesn't exist ») ;
 * SQLite (tests) : « no such table ». Toute autre erreur SQL (syntaxe, colonne inconnue,
 * connexion perdue, droits…) n'est PAS reconnue : l'appelant doit la laisser remonter.
 *
 * Sert aux lectures qui doivent rester debout quand un module n'est pas encore installé
 * (ex. banc énergie : `energieData` absente tant que 2026_10_energie_tables.sql n'est pas
 * appliquée — erreurs 500 sur /energie du 14/11/2026).
 */
final class MissingTable
{
    public static function is(\Throwable $e): bool
    {
        if (!$e instanceof \PDOException) {
            return false;
        }
        $sqlState = (string) $e->getCode();
        $driverCode = is_array($e->errorInfo) ? (int) ($e->errorInfo[1] ?? 0) : 0;
        if ($sqlState === '42S02' || $driverCode === 1146) {
            return true;
        }
        $message = $e->getMessage();

        return str_contains($message, 'SQLSTATE[42S02]')
            || str_contains($message, 'no such table');
    }
}

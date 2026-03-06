<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Validation des noms de tables pour prévenir les injections SQL.
 * 
 * Centralise les whitelists de tables autorisées.
 */
class TableValidator
{
    /** Tables de données capteurs autorisées */
    public const DATA_TABLES = ['ffp3Data', 'ffp3Data2', 'ffp3Data3', 'ffp3Data4'];

    /** Tables d'outputs (GPIO) autorisées */
    public const OUTPUT_TABLES = ['ffp3Outputs', 'ffp3Outputs2', 'ffp3Outputs3', 'ffp3Outputs4'];

    /** Tables de boards autorisées */
    public const BOARD_TABLES = ['ffp3Boards', 'ffp3Boards2'];

    /**
     * Valide un nom de table de données capteurs
     *
     * @param string $tableName Nom de la table à valider
     * @return string Nom validé
     * @throws \InvalidArgumentException Si le nom n'est pas dans la whitelist
     */
    public static function validateDataTable(string $tableName): string
    {
        return self::validate($tableName, self::DATA_TABLES, 'data');
    }

    /**
     * Valide un nom de table d'outputs
     *
     * @param string $tableName Nom de la table à valider
     * @return string Nom validé
     * @throws \InvalidArgumentException Si le nom n'est pas dans la whitelist
     */
    public static function validateOutputsTable(string $tableName): string
    {
        return self::validate($tableName, self::OUTPUT_TABLES, 'outputs');
    }

    /**
     * Valide un nom de table de boards
     *
     * @param string $tableName Nom de la table à valider
     * @return string Nom validé
     * @throws \InvalidArgumentException Si le nom n'est pas dans la whitelist
     */
    public static function validateBoardsTable(string $tableName): string
    {
        return self::validate($tableName, self::BOARD_TABLES, 'boards');
    }

    /**
     * Valide un nom de table contre une whitelist
     *
     * @param string $tableName Nom de la table
     * @param array $allowedTables Liste des tables autorisées
     * @param string $tableType Type de table (pour le message d'erreur)
     * @return string Nom validé
     * @throws \InvalidArgumentException Si le nom n'est pas dans la whitelist
     */
    private static function validate(string $tableName, array $allowedTables, string $tableType): string
    {
        if (!in_array($tableName, $allowedTables, true)) {
            throw new \InvalidArgumentException(
                sprintf(
                    "Table name not allowed for %s: %s. Allowed: %s",
                    $tableType,
                    $tableName,
                    implode(', ', $allowedTables)
                )
            );
        }
        return $tableName;
    }
}

<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Lecture transverse d'une valeur d'output (paramètre GPIO) dans une table outputs
 * DONNÉE, toutes familles confondues (FFP3, N3PP, MSP1).
 *
 * Là où {@see OutputRepository} est lié à la table FFP3 de l'environnement courant
 * (via {@see \App\Config\TableConfig::getOutputsTable()}), ce repository ne fait que LIRE
 * l'état d'un GPIO précis dans une table explicitement fournie, afin que la supervision
 * ({@see \App\Service\OfflineThresholdResolver}) reconstitue le temps de veille configuré
 * en BDD (FreqWakeUp) pour n'importe quelle famille. La table cible est validée contre une
 * whitelist stricte couvrant toutes les variantes d'environnement, pour exclure toute
 * injection (le nom provient de TableConfig).
 */
class OutputMonitorRepository extends AbstractRepository
{
    /**
     * Whitelist de toutes les tables outputs lisibles (prod + variantes de test),
     * pour les trois familles supervisées.
     */
    public const ALLOWED_TABLES = [
        // FFP3 (aquaponie)
        'ffp3Outputs',
        'ffp3Outputs2',
        'ffp3Outputs3',
        'ffp3OutputsS3',
        'ffp3OutputsS3Test',
        // N3PP (serre / élevage)
        'n3ppOutputs',
        'n3ppOutputsTest',
        // MSP1 (station météo)
        'msp1Outputs',
        'msp1OutputsTest',
    ];

    /**
     * Retourne l'état brut (chaîne) du GPIO demandé dans la table donnée, ou null si la
     * ligne est absente / vide. Le caller reste responsable du typage (int/float).
     *
     * @param string $table Nom de table issu de TableConfig (validé en interne)
     *
     * @throws \InvalidArgumentException Si la table n'est pas dans la whitelist.
     */
    public function findStateByGpio(string $table, int $gpio): ?string
    {
        $validated = $this->validateTable($table);

        $row = $this->fetchOne(
            "SELECT state FROM `{$validated}` WHERE gpio = :gpio LIMIT 1",
            [':gpio' => $gpio]
        );

        if ($row === null || !array_key_exists('state', $row) || $row['state'] === null) {
            return null;
        }

        return (string) $row['state'];
    }

    /**
     * Valide un nom de table outputs contre la whitelist.
     *
     * @throws \InvalidArgumentException
     */
    private function validateTable(string $table): string
    {
        if (!in_array($table, self::ALLOWED_TABLES, true)) {
            throw new \InvalidArgumentException(
                'Table outputs non autorisée : ' . $table . '. Autorisées : '
                . implode(', ', self::ALLOWED_TABLES)
            );
        }

        return $table;
    }
}

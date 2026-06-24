<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Lecture transverse de l'état des heartbeats firmware, toutes familles confondues
 * (FFP3, N3PP, MSP1).
 *
 * Là où {@see HeartbeatRepository} gère l'écriture FFP3, ce repository ne fait que LIRE
 * la dernière date de battement de cœur d'une table donnée, afin que la supervision
 * (DeviceHealthService) détecte un appareil devenu silencieux. La table cible est validée
 * contre une whitelist stricte couvrant toutes les variantes d'environnement, pour exclure
 * toute injection (le nom provient de TableConfig).
 */
class HeartbeatMonitorRepository extends AbstractRepository
{
    /**
     * Whitelist de toutes les tables heartbeat lisibles (prod + variantes de test),
     * pour les trois familles supervisées.
     */
    public const ALLOWED_TABLES = [
        // FFP3 (aquaponie)
        'ffp3Heartbeat',
        'ffp3Heartbeat2',
        'ffp3Heartbeat3',
        'ffp3HeartbeatS3',
        'ffp3HeartbeatS3Test',
        // N3PP (serre / élevage)
        'n3ppHeartbeat',
        'n3ppHeartbeatTest',
        // MSP1 (station météo)
        'msp1Heartbeat',
        'msp1HeartbeatTest',
    ];

    /**
     * Retourne la date SQL du dernier heartbeat reçu dans la table donnée, ou null si la
     * table est vide (aucun appareil n'a jamais émis).
     *
     * @param string $table Nom de table issu de TableConfig (validé en interne)
     *
     * @throws \InvalidArgumentException Si la table n'est pas dans la whitelist.
     */
    public function getLastHeartbeatDate(string $table): ?string
    {
        $validated = $this->validateTable($table);

        $value = $this->fetchScalar("SELECT MAX(reading_time) FROM `{$validated}`");

        return $value !== null ? (string) $value : null;
    }

    /**
     * Valide un nom de table heartbeat contre la whitelist.
     *
     * @throws \InvalidArgumentException
     */
    private function validateTable(string $table): string
    {
        if (!in_array($table, self::ALLOWED_TABLES, true)) {
            throw new \InvalidArgumentException(
                'Table heartbeat non autorisée : ' . $table . '. Autorisées : '
                . implode(', ', self::ALLOWED_TABLES)
            );
        }

        return $table;
    }
}

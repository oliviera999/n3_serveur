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
 *
 * ⚠️ Colonne de date PAR FAMILLE (incident du 06/11/2026) : les tables FFP3 historiques
 * (`ffp3Heartbeat*`) datent leurs lignes dans `timestamp`, les tables N3PP / MSP1 créées
 * par `CREATE_LEGACY_HEARTBEAT_TABLES.sql` dans `reading_time`. Lire `reading_time` sur
 * `ffp3Heartbeat` levait « Unknown column 'reading_time' » à chaque passage horaire :
 * la supervision heartbeat FFP3 était AVEUGLE (fail-safe silencieux) depuis sa mise en
 * place. {@see self::timeColumnFor()} est la source unique de vérité de cette correspondance.
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
     * Colonne horodatant une ligne de heartbeat, selon la table.
     *
     * `ffp3Heartbeat*` → `timestamp` (schéma historique, `DEFAULT current_timestamp()`) ;
     * `n3ppHeartbeat*` / `msp1Heartbeat*` → `reading_time`.
     */
    public static function timeColumnFor(string $table): string
    {
        return str_starts_with($table, 'ffp3Heartbeat') ? 'timestamp' : 'reading_time';
    }

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
        $column = self::timeColumnFor($validated);

        $value = $this->fetchScalar("SELECT MAX(`{$column}`) FROM `{$validated}`");

        return $value !== null ? (string) $value : null;
    }

    /**
     * Horodatages des `$limit` derniers heartbeats (du plus récent au plus ancien), pour
     * reconstituer le rythme des réveils ({@see \App\Service\Availability\WakeScheduleModel}).
     *
     * @return list<string>
     */
    public function getRecentHeartbeatDates(string $table, int $limit = 200): array
    {
        $validated = $this->validateTable($table);
        $column = self::timeColumnFor($validated);
        $limit = max(1, min(2000, $limit));

        $rows = $this->fetchAll("SELECT `{$column}` AS t FROM `{$validated}` ORDER BY `{$column}` DESC, id DESC LIMIT {$limit}");

        return array_values(array_map(static fn (array $r): string => (string) $r['t'], $rows));
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

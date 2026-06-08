<?php

declare(strict_types=1);

namespace App\Repository;

use App\Config\TableConfig;

/**
 * Repository des battements de coeur firmware (uptime, free heap, reboots).
 *
 * La table cible est determinee par l'environnement courant via TableConfig
 * (`ffp3Heartbeat`, `ffp3Heartbeat2`, etc.). La validation est faite via une
 * whitelist stricte, identique a celle utilisee historiquement par HeartbeatController.
 */
final class HeartbeatRepository extends AbstractRepository
{
    /**
     * Tables autorisees (whitelist) pour les ecritures heartbeat.
     */
    private const ALLOWED_TABLES = [
        'ffp3Heartbeat',
        'ffp3Heartbeat2',
        'ffp3Heartbeat3',
        'ffp3HeartbeatS3',
        'ffp3HeartbeatS3Test',
    ];

    /**
     * Insere un battement de coeur dans la table correspondant a l'environnement courant.
     *
     * @throws \RuntimeException si la table issue de l'env n'est pas dans la whitelist.
     */
    public function insert(int $uptime, int $freeHeap, int $minHeap, int $reboots): void
    {
        $table = TableConfig::getHeartbeatTable();
        if (!in_array($table, self::ALLOWED_TABLES, true)) {
            throw new \RuntimeException("Table heartbeat invalide: {$table}");
        }

        $this->execute(
            "INSERT INTO `{$table}` (uptime, freeHeap, minHeap, reboots) "
                . 'VALUES (:uptime, :free, :min, :reboots)',
            [
                ':uptime' => $uptime,
                ':free' => $freeHeap,
                ':min' => $minHeap,
                ':reboots' => $reboots,
            ]
        );
    }
}

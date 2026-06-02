<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

class PglRepository extends AbstractRepository
{
    public function insertEvent(
        string $boardId,
        string $eventTime,
        int $countDelta,
        string $sensorMode,
        bool $tandemValidated,
        ?float $batteryV,
        ?int $rssi,
        string $fwVersion
    ): void {
        $sql = 'INSERT INTO pglEvents
            (board, event_time, count_delta, sensor_mode, is_tandem_validated, battery_v, rssi, fw_version)
            VALUES
            (:board, :event_time, :count_delta, :sensor_mode, :is_tandem_validated, :battery_v, :rssi, :fw_version)';

        $this->execute($sql, [
            ':board' => $boardId,
            ':event_time' => $eventTime,
            ':count_delta' => $countDelta,
            ':sensor_mode' => $sensorMode,
            ':is_tandem_validated' => $tandemValidated ? 1 : 0,
            ':battery_v' => $batteryV,
            ':rssi' => $rssi,
            ':fw_version' => $fwVersion,
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    public function getHourlyStats(int $hours = 48): array
    {
        $sql = 'SELECT DATE_FORMAT(event_time, "%Y-%m-%d %H:00:00") AS bucket, SUM(count_delta) AS total
                FROM pglEvents
                WHERE event_time >= DATE_SUB(NOW(), INTERVAL :hours HOUR)
                GROUP BY DATE_FORMAT(event_time, "%Y-%m-%d %H:00:00")
                ORDER BY bucket ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':hours', $hours, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int, array<string, mixed>> */
    public function getDailyStats(int $days = 30): array
    {
        $sql = 'SELECT DATE(event_time) AS bucket, SUM(count_delta) AS total
                FROM pglEvents
                WHERE event_time >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
                GROUP BY DATE(event_time)
                ORDER BY bucket ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getTotalCount(): int
    {
        $value = $this->fetchScalar('SELECT COALESCE(SUM(count_delta), 0) FROM pglEvents');
        return (int) ($value ?? 0);
    }
}

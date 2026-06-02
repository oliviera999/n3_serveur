<?php

declare(strict_types=1);

namespace App\Repository;

use App\Config\PglConfig;
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

    public function insertHeartbeat(
        int $uptime,
        int $freeHeap,
        int $minHeap,
        int $reboots,
        ?int $rssi,
        ?string $sensor,
        ?string $version
    ): void {
        $sql = 'INSERT INTO pglHeartbeat (uptime, freeHeap, minHeap, reboots, rssi, sensor, version)
                VALUES (:uptime, :free, :min, :reboots, :rssi, :sensor, :version)';

        $this->execute($sql, [
            ':uptime' => $uptime,
            ':free' => $freeHeap,
            ':min' => $minHeap,
            ':reboots' => $reboots,
            ':rssi' => $rssi,
            ':sensor' => $sensor,
            ':version' => $version,
        ]);
    }

    public function getLastHeartbeatDate(): ?string
    {
        $value = $this->fetchScalar('SELECT MAX(reading_time) FROM pglHeartbeat');
        return $value !== null ? (string) $value : null;
    }

    public function getLastEventDate(string $boardId = PglConfig::DEFAULT_BOARD_ID): ?string
    {
        $value = $this->fetchScalar(
            'SELECT MAX(event_time) FROM pglEvents WHERE board = :board',
            [':board' => $boardId]
        );
        return $value !== null ? (string) $value : null;
    }

    /**
     * @return array{online: bool|null, last_reading: string|null, last_reading_ago_seconds: int|null, source: string|null}
     */
    public function getSystemHealth(int $thresholdSeconds): array
    {
        if (!PglConfig::ONLINE_CHECK_ENABLED) {
            return [
                'online' => null,
                'last_reading' => null,
                'last_reading_ago_seconds' => null,
                'source' => null,
            ];
        }

        $lastHb = $this->getLastHeartbeatDate();
        $lastEv = $this->getLastEventDate(PglConfig::DEFAULT_BOARD_ID);

        $lastReading = null;
        $source = null;

        if ($lastHb !== null && $lastEv !== null) {
            if (strtotime($lastHb) >= strtotime($lastEv)) {
                $lastReading = $lastHb;
                $source = 'heartbeat';
            } else {
                $lastReading = $lastEv;
                $source = 'event';
            }
        } elseif ($lastHb !== null) {
            $lastReading = $lastHb;
            $source = 'heartbeat';
        } elseif ($lastEv !== null) {
            $lastReading = $lastEv;
            $source = 'event';
        }

        if ($lastReading === null) {
            return [
                'online' => false,
                'last_reading' => null,
                'last_reading_ago_seconds' => null,
                'source' => null,
            ];
        }

        $lastTs = strtotime($lastReading);
        if ($lastTs === false) {
            return [
                'online' => false,
                'last_reading' => $lastReading,
                'last_reading_ago_seconds' => null,
                'source' => $source,
            ];
        }

        $secondsAgo = time() - $lastTs;

        return [
            'online' => $secondsAgo < $thresholdSeconds,
            'last_reading' => $lastReading,
            'last_reading_ago_seconds' => $secondsAgo,
            'source' => $source,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Realtime;

/**
 * Interface commune pour les fournisseurs de données temps réel.
 * Implémentée par Ffp3RealtimeDataProvider, MspRealtimeDataProvider, N3ppRealtimeDataProvider.
 */
interface RealtimeDataProviderInterface
{
    /**
     * @return array{timestamp: int, reading_time: string|null, sensors: array<string, mixed>}
     */
    public function getLatestReadings(): array;

    /**
     * @param int $sinceTimestamp Timestamp Unix
     * @return array<int, array{timestamp: int, reading_time: string, sensors: array<string, mixed>}>
     */
    public function getReadingsSince(int $sinceTimestamp): array;

    /**
     * @return array{
     *   online: bool,
     *   last_reading: string|null,
     *   last_reading_ago_seconds: int|null,
     *   uptime_percentage: float,
     *   readings_today: int,
     *   average_latency_seconds: float|null,
     *   device_ip: string|null
     * }
     */
    public function getSystemHealth(): array;

    /**
     * @return array<int, array{id: int|null, gpio: int, name: string, state: mixed, board: int|null}>
     */
    public function getOutputsState(): array;

    /**
     * @return array<int, mixed>
     */
    public function getActiveAlerts(): array;
}

<?php

declare(strict_types=1);

namespace App\Service\Realtime;

/**
 * Contrat commun pour les fournisseurs de données temps réel (FFP3, MSP1, N3PP).
 * Permet d'unifier les APIs realtime derrière un contrôleur générique.
 */
interface RealtimeDataProviderInterface
{
    /**
     * Dernières lectures capteurs.
     *
     * @return array{timestamp: int, reading_time: string|null, sensors: array<string, mixed>}
     */
    public function getLatestReadings(): array;

    /**
     * Lectures depuis un timestamp donné.
     *
     * @return array<int, array{timestamp: int, reading_time: string, sensors: array<string, mixed>}>
     */
    public function getReadingsSince(int $sinceTimestamp): array;

    /**
     * Santé système (online, last_reading, etc.).
     *
     * @return array{online: bool, last_reading: string|null, last_reading_ago_seconds: int|null, uptime_percentage: float, readings_today: int, average_latency_seconds: float|null}
     */
    public function getSystemHealth(): array;

    /**
     * État des sorties/GPIO.
     *
     * @return array<int, array{id: int|null, gpio: int, name: string, state: int|string, board: int|null}>
     */
    public function getOutputsState(): array;

    /**
     * Alertes actives (FFP3 uniquement ; MSP/N3PP retournent []).
     *
     * @return array<int, mixed>
     */
    public function getActiveAlerts(): array;
}

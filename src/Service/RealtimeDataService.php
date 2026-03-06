<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\TableConfig;
use App\Repository\SensorReadRepository;
use App\Repository\OutputRepository;
use PDO;

/**
 * Service pour la gestion des données en temps réel
 * Fournit les dernières lectures capteurs et l'état du système
 */
class RealtimeDataService
{
    /**
     * Seuil pour considérer le système en ligne (en secondes)
     * 10 minutes = 600 secondes
     */
    private const ONLINE_THRESHOLD_SECONDS = 600;

    /**
     * Intervalle attendu entre les lectures ESP32 (en minutes)
     */
    private const EXPECTED_READING_INTERVAL_MINUTES = 3;

    /**
     * Latence moyenne estimée pour les lectures (en secondes)
     */
    private const ESTIMATED_LATENCY_SECONDS = 3.5;

    /**
     * Période par défaut pour le calcul d'uptime (en jours)
     */
    private const DEFAULT_UPTIME_DAYS = 30;

    /**
     * GPIO considérés comme booléens (< 100 + quelques exceptions)
     */
    private const BOOLEAN_GPIO_EXCEPTIONS = [101, 108, 109, 110, 115];

    public function __construct(
        private SensorReadRepository $sensorReadRepo,
        private OutputRepository $outputRepo,
        private PDO $pdo
    ) {
    }

    /**
     * Récupère les dernières lectures de tous les capteurs avec timestamp
     * 
     * @return array{
     *   timestamp: int,
     *   reading_time: string,
     *   sensors: array<string, mixed>
     * }
     */
    public function getLatestReadings(): array
    {
        $lastReadings = $this->sensorReadRepo->getLastReadings();
        
        if (!$lastReadings) {
            return [
                'timestamp' => time(),
                'reading_time' => null,
                'sensors' => [],
            ];
        }

        // Convertir reading_time en timestamp Unix pour faciliter la comparaison côté client
        $readingTimestamp = strtotime($lastReadings['reading_time']);

        return [
            'timestamp' => $readingTimestamp,
            'reading_time' => $lastReadings['reading_time'],
            'sensors' => [
                'EauAquarium' => $lastReadings['EauAquarium'] ?? null,
                'EauReserve' => $lastReadings['EauReserve'] ?? null,
                'EauPotager' => $lastReadings['EauPotager'] ?? null,
                'TempEau' => $lastReadings['TempEau'] ?? null,
                'TempAir' => $lastReadings['TempAir'] ?? null,
                'Humidite' => $lastReadings['Humidite'] ?? null,
                'Luminosite' => $lastReadings['Luminosite'] ?? null,
                // États des actionneurs (scatter/column dans les graphiques)
                'etatPompeAqua' => $lastReadings['etatPompeAqua'] ?? null,
                'etatPompeTank' => $lastReadings['etatPompeTank'] ?? null,
                'etatHeat' => $lastReadings['etatHeat'] ?? null,
                'etatUV' => $lastReadings['etatUV'] ?? null,
                'bouffePetits' => $lastReadings['bouffePetits'] ?? null,
                'bouffeGros' => $lastReadings['bouffeGros'] ?? null,
            ],
        ];
    }

    /**
     * Récupère les nouvelles lectures depuis un timestamp donné
     * 
     * @param int $sinceTimestamp Timestamp Unix
     * @return array Liste des nouvelles lectures
     */
    public function getReadingsSince(int $sinceTimestamp): array
    {
        $sinceDate = date('Y-m-d H:i:s', $sinceTimestamp);
        
        $readings = $this->sensorReadRepo->getReadingsSince($sinceDate);
        
        // Transformer pour un format plus facile à consommer côté client
        $result = [];
        foreach ($readings as $reading) {
            $result[] = [
                'timestamp' => strtotime($reading['reading_time']),
                'reading_time' => $reading['reading_time'],
                'sensors' => [
                    'EauAquarium' => $reading['EauAquarium'] ?? null,
                    'EauReserve' => $reading['EauReserve'] ?? null,
                    'EauPotager' => $reading['EauPotager'] ?? null,
                    'TempEau' => $reading['TempEau'] ?? null,
                    'TempAir' => $reading['TempAir'] ?? null,
                    'Humidite' => $reading['Humidite'] ?? null,
                    'Luminosite' => $reading['Luminosite'] ?? null,
                    // États des actionneurs (scatter/column dans les graphiques)
                    'etatPompeAqua' => $reading['etatPompeAqua'] ?? null,
                    'etatPompeTank' => $reading['etatPompeTank'] ?? null,
                    'etatHeat' => $reading['etatHeat'] ?? null,
                    'etatUV' => $reading['etatUV'] ?? null,
                    'bouffePetits' => $reading['bouffePetits'] ?? null,
                    'bouffeGros' => $reading['bouffeGros'] ?? null,
                ],
            ];
        }
        
        return $result;
    }

    /**
     * Calcule la santé/statut du système
     * 
     * @return array{
     *   online: bool,
     *   last_reading: string|null,
     *   last_reading_ago_seconds: int|null,
     *   uptime_percentage: float,
     *   readings_today: int,
     *   average_latency_seconds: float|null
     * }
     */
    public function getSystemHealth(): array
    {
        $lastReadingDateStr = $this->sensorReadRepo->getLastReadingDate();
        
        if ($lastReadingDateStr === null) {
            return [
                'online' => false,
                'last_reading' => null,
                'last_reading_ago_seconds' => null,
                'uptime_percentage' => 0.0,
                'readings_today' => 0,
                'average_latency_seconds' => null,
                'device_ip' => $this->readDeviceIpFile(),
            ];
        }

        $lastReadingTimestamp = strtotime($lastReadingDateStr);
        $now = time();
        $secondsSinceLastReading = $now - $lastReadingTimestamp;
        
        // Système considéré online si dernière donnée < seuil
        $isOnline = $secondsSinceLastReading < self::ONLINE_THRESHOLD_SECONDS;

        // Calculer uptime sur la période par défaut
        $uptimePercentage = $this->calculateUptime(self::DEFAULT_UPTIME_DAYS);

        // Compter les lectures aujourd'hui
        $readingsToday = $this->countReadingsToday();

        // Latence moyenne estimée
        $averageLatency = $isOnline ? self::ESTIMATED_LATENCY_SECONDS : null;

        return [
            'online' => $isOnline,
            'last_reading' => $lastReadingDateStr,
            'last_reading_ago_seconds' => $secondsSinceLastReading,
            'uptime_percentage' => $uptimePercentage,
            'readings_today' => $readingsToday,
            'average_latency_seconds' => $averageLatency,
            'device_ip' => $this->readDeviceIpFile(),
        ];
    }

    /**
     * Lit l'IP locale ESP depuis le fichier écrit par HeartbeatController
     */
    private function readDeviceIpFile(): ?string
    {
        $projectRoot = dirname(__DIR__, 2);
        $env = TableConfig::getEnvironment();
        $path = $projectRoot . '/var/last_device_ip_' . $env . '.txt';
        if (!is_file($path)) {
            return null;
        }
        $content = @file_get_contents($path);
        if ($content === false) {
            return null;
        }
        $trimmed = trim($content);
        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Récupère l'état actuel de tous les outputs/GPIO
     * 
     * @return array Liste des outputs avec leur état
     */
    public function getOutputsState(): array
    {
        $outputs = $this->outputRepo->findAll();

        // Construire la liste des GPIO booléens (< 100 + exceptions)
        $booleanGpios = [];
        for ($i = 0; $i < 100; $i++) {
            $booleanGpios[$i] = true;
        }
        foreach (self::BOOLEAN_GPIO_EXCEPTIONS as $specialGpio) {
            $booleanGpios[$specialGpio] = true;
        }

        $result = [];
        foreach ($outputs as $output) {
            $gpio = (int)($output['gpio'] ?? 0);
            $state = $output['state'];

            if (isset($booleanGpios[$gpio])) {
                $state = (int)$state;
            }

            $result[] = [
                'id' => isset($output['id']) ? (int)$output['id'] : null,
                'gpio' => $gpio,
                'name' => $output['name'],
                'state' => $state,
                'board' => $output['board'] ?? null,
            ];
        }

        return $result;
    }

    /**
     * Récupère les alertes actives (placeholder pour future implémentation)
     * 
     * @return array Liste des alertes
     */
    public function getActiveAlerts(): array
    {
        // Pour l'instant, on retourne un tableau vide
        // À implémenter avec une vraie table d'alertes dans le futur
        return [];
    }

    /**
     * Calcule le pourcentage d'uptime sur N jours
     * 
     * @param int $days Nombre de jours
     * @return float Pourcentage d'uptime (0-100)
     */
    private function calculateUptime(int $days): float
    {
        $startDate = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $endDate = date('Y-m-d H:i:s');

        // Compter le nombre d'intervalles attendus
        $expectedReadings = ($days * 24 * 60) / self::EXPECTED_READING_INTERVAL_MINUTES;

        // Compter les lectures réelles
        $actualReadings = $this->sensorReadRepo->countReadingsBetween($startDate, $endDate);

        if ($expectedReadings == 0) {
            return 0.0;
        }

        $uptime = ($actualReadings / $expectedReadings) * 100;
        
        // Cap à 100% au cas où il y aurait plus de lectures que prévu
        return min($uptime, 100.0);
    }

    /**
     * Compte le nombre de lectures reçues aujourd'hui
     * 
     * @return int Nombre de lectures
     */
    private function countReadingsToday(): int
    {
        $startOfDay = date('Y-m-d 00:00:00');
        $endOfDay = date('Y-m-d 23:59:59');

        return $this->sensorReadRepo->countReadingsBetween($startOfDay, $endOfDay);
    }
}


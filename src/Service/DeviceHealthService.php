<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\TableConfig;
use App\Notification\NotificationCategory;
use App\Notification\Severity;
use App\Repository\HeartbeatMonitorRepository;

/**
 * Supervision « appareil silencieux » (heartbeat) GÉNÉRALISÉE À TOUTES LES FAMILLES.
 *
 * Historiquement, seul FFP3 alertait quand un appareil cessait d'émettre
 * (via SystemHealthService, sur la table de données). Ce service factorise la logique
 * en la rendant PARAMÉTRIQUE PAR FAMILLE : il interroge la dernière date de heartbeat de
 * chaque famille (FFP3 `ffp3Heartbeat`, N3PP `n3ppHeartbeat`, MSP1 `msp1Heartbeat` —
 * noms résolus via {@see TableConfig}) et, si le dernier battement dépasse le seuil
 * d'inactivité, route une alerte P1/Disponibilité via {@see NotificationService}.
 *
 * Anti-spam : chaque famille a sa propre clé de throttle (`heartbeat:offline:<family>`),
 * donc l'AlertThrottler dé-duplique sans jamais inonder (cooldown P1 par défaut).
 *
 * Choix de robustesse : une table SANS aucun heartbeat (null) est IGNORÉE (l'appareil
 * n'a jamais émis = famille non déployée), afin de ne pas générer de fausses alertes à
 * chaque cycle CRON. On n'alerte que sur un appareil ayant un historique devenu obsolète.
 */
class DeviceHealthService
{
    /** Seuil d'inactivité par défaut (secondes) avant de considérer un appareil silencieux. */
    private const DEFAULT_OFFLINE_THRESHOLD_SECONDS = 3600;

    private int $offlineThresholdSeconds;

    /**
     * @param array<int, array{family: string, table: string}>|null $families
     *        Familles à superviser (défaut : FFP3, N3PP, MSP1 via TableConfig).
     *        Surchargeable pour les tests.
     */
    public function __construct(
        private HeartbeatMonitorRepository $heartbeatRepo,
        private NotificationService $notifier,
        private LogService $logger,
        ?int $offlineThresholdSeconds = null,
        private ?array $families = null,
        private ?OfflineThresholdResolver $thresholdResolver = null,
        private ?OperationalSettingsService $operationalSettings = null,
    ) {
        $this->offlineThresholdSeconds = $offlineThresholdSeconds
            ?? $this->operationalSettings?->int('HEARTBEAT_OFFLINE_THRESHOLD_SECONDS', self::DEFAULT_OFFLINE_THRESHOLD_SECONDS)
            ?? (int) ($_ENV['HEARTBEAT_OFFLINE_THRESHOLD_SECONDS'] ?? self::DEFAULT_OFFLINE_THRESHOLD_SECONDS);
        if ($this->offlineThresholdSeconds <= 0) {
            $this->offlineThresholdSeconds = self::DEFAULT_OFFLINE_THRESHOLD_SECONDS;
        }
    }

    /**
     * Familles supervisées par défaut, avec leur table heartbeat résolue par l'environnement.
     *
     * @return array<int, array{family: string, table: string}>
     */
    public static function defaultFamilies(): array
    {
        return [
            ['family' => 'FFP3', 'table' => TableConfig::getHeartbeatTable()],
            ['family' => 'N3PP', 'table' => TableConfig::getN3ppHeartbeatTable()],
            ['family' => 'MSP1', 'table' => TableConfig::getMspHeartbeatTable()],
        ];
    }

    /**
     * Vérifie chaque famille et alerte sur celles dont l'appareil est silencieux.
     *
     * @return int Nombre de familles ayant déclenché une alerte (utile pour le log / les tests)
     */
    public function checkAllFamilies(): int
    {
        $families = $this->families ?? self::defaultFamilies();
        $alerted = 0;

        foreach ($families as $entry) {
            if ($this->checkFamily($entry['family'], $entry['table'])) {
                $alerted++;
            }
        }

        return $alerted;
    }

    /**
     * Vérifie une famille donnée. Retourne vrai si une alerte a été émise.
     */
    public function checkFamily(string $family, string $table): bool
    {
        try {
            $lastSeen = $this->heartbeatRepo->getLastHeartbeatDate($table);
        } catch (\Throwable $e) {
            // Table indisponible / invalide : on log et on n'alerte pas (fail-safe).
            $this->logger->warning('DeviceHealthService: lecture heartbeat impossible', [
                'family' => $family,
                'table' => $table,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        if ($lastSeen === null) {
            // Aucun heartbeat : famille non déployée -> on ne génère pas de bruit.
            $this->logger->info('DeviceHealthService: aucun heartbeat (famille ignorée)', [
                'family' => $family,
            ]);

            return false;
        }

        $lastTs = strtotime($lastSeen);
        if ($lastTs === false) {
            $this->logger->error('DeviceHealthService: date heartbeat invalide', [
                'family' => $family,
                'value' => $lastSeen,
            ]);

            return false;
        }

        $threshold = $this->resolveThresholdSeconds($family);
        $ageSeconds = time() - $lastTs;
        if ($ageSeconds <= $threshold) {
            $this->logger->info('DeviceHealthService: appareil en ligne', [
                'family' => $family,
                'age_seconds' => $ageSeconds,
                'threshold_seconds' => $threshold,
            ]);

            return false;
        }

        return $this->alertOffline($family, $ageSeconds, $threshold);
    }

    /**
     * Seuil d'inactivité (s) pour la famille : dérivé du temps de veille en BDD si un
     * {@see OfflineThresholdResolver} est fourni (tient compte du facteur nuit FFP3),
     * sinon le forfait historique ({@see $offlineThresholdSeconds}).
     */
    private function resolveThresholdSeconds(string $family): int
    {
        if ($this->thresholdResolver !== null) {
            return $this->thresholdResolver->resolveForFamily($family);
        }

        return $this->offlineThresholdSeconds;
    }

    /**
     * Émet l'alerte « appareil silencieux » pour une famille. L'anti-spam (clé par famille)
     * empêche les doublons à chaque cycle CRON.
     */
    private function alertOffline(string $family, int $ageSeconds, int $thresholdSeconds): bool
    {
        $minutes = (int) round($ageSeconds / 60);
        $this->logger->critical('DeviceHealthService: appareil silencieux', [
            'family' => $family,
            'age_seconds' => $ageSeconds,
        ]);

        $message = sprintf(
            "Aucun battement de cœur (heartbeat) reçu de l'appareil %s depuis environ %d minute(s) "
            . "(seuil : %d minute(s)).\nL'appareil ne transmet plus : vérifiez son alimentation, "
            . 'sa connexion réseau ou son firmware.',
            strtoupper($family),
            $minutes,
            (int) round($thresholdSeconds / 60)
        );

        return $this->notifier->sendAlert(
            Severity::Critical,
            NotificationCategory::Availability,
            $family,
            'Appareil silencieux (heartbeat)',
            $message,
            'heartbeat:offline:' . strtolower($family)
        );
    }
}

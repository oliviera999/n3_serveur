<?php

declare(strict_types=1);

namespace App\Service;

use App\Notification\NotificationCategory;
use App\Notification\Severity;
use App\Repository\OutputRepository;
use App\Repository\SensorReadRepository;

/**
 * Service de surveillance de l'état de santé du système.
 * Permet de vérifier si le système est en ligne (données récentes) et d'alerter en cas de problème.
 * Peut être enrichi pour surveiller d'autres indicateurs (niveau d'eau, batterie, etc.).
 */
class SystemHealthService
{
    /** GPIO server-only (FFP3) : seuil réserve basse en mm (vide/0 = alerte désactivée). */
    private const RESERVE_LOW_THRESHOLD_GPIO = 130;

    /**
     * @param SensorReadRepository  $sensorReadRepo Accès aux données capteurs
     * @param NotificationService   $notifier       Service de notification (e-mail)
     * @param LogService            $logger         Service de log
     * @param OutputRepository|null $outputRepo     Lecture des seuils pilotés en BDD (optionnel)
     */
    public function __construct(
        private SensorReadRepository $sensorReadRepo,
        private NotificationService $notifier,
        private LogService $logger,
        private ?OutputRepository $outputRepo = null,
        private ?OperationalSettingsService $operationalSettings = null,
    ) {
    }

    /**
     * Vérifie si le système est en ligne en se basant sur la date de la dernière lecture capteur.
     * Si la dernière donnée est trop ancienne, logge une alerte critique (et peut notifier).
     *
     * @param int $maxOfflineSeconds Délai max (en secondes) sans nouvelle donnée avant alerte
     */
    public function checkOnlineStatus(int $maxOfflineSeconds = 3600): void
    {
        $lastReadingDateStr = $this->sensorReadRepo->getLastReadingDate();
        if ($lastReadingDateStr === null) {
            $this->logger->warning('Aucune donnée de capteur trouvée, impossible de vérifier le statut en ligne.');
            // Notification dédiée
            $this->notifier->notifyNoSensorData();
            return;
        }

        $lastReadingTimestamp = strtotime($lastReadingDateStr);
        if ($lastReadingTimestamp === false) {
            // Format inattendu : on log et on considère le système offline
            $this->logger->error('Format de date invalide pour la dernière lecture', ['value' => $lastReadingDateStr]);
            $this->notifier->notifySystemOffline();
            return;
        }

        $now = time();

        if (($now - $lastReadingTimestamp) > $maxOfflineSeconds) {
            $this->logger->critical('Le système semble hors ligne !');
            $this->notifier->notifySystemOffline();
        } else {
            $this->logger->info('Le système est en ligne.');
        }
    }

    /**
     * Vérifie le niveau de la réserve et envoie une alerte si la distance capteur→surface
     * dépasse le seuil configuré (réserve basse = surface loin du capteur).
     *
     * Opt-in : sans `RESERVE_LOW_LEVEL_THRESHOLD` dans `.env`, seul un log informatif est émis.
     * Aucune action physique (pompe) n'est déclenchée.
     */
    public function checkTankLevel(): void
    {
        $threshold = $this->resolveReserveLowThreshold();
        if ($threshold === null) {
            $this->logger->info(
                'Vérification du niveau du réservoir : seuil non configuré (RESERVE_LOW_LEVEL_THRESHOLD), alerte désactivée.'
            );

            return;
        }

        $lastReading = $this->sensorReadRepo->getLastReadings();
        $lastReserveLevel = $lastReading['EauReserve'] ?? null;

        $this->logger->info('Vérification du niveau du réservoir', [
            'EauReserve' => $lastReserveLevel,
            'threshold' => $threshold,
        ]);

        if ($lastReserveLevel === null || (float) $lastReserveLevel <= $threshold) {
            return;
        }

        $message = sprintf(
            "La distance capteur→surface de la réserve a atteint %.0f mm (seuil %.0f mm).\n"
            . "La réserve est considérée basse. Aucune action automatique n'a été déclenchée.",
            (float) $lastReserveLevel,
            $threshold
        );

        $this->notifier->sendAlert(
            Severity::Alert,
            NotificationCategory::Hydraulic,
            'FFP3',
            'Niveau de réserve bas',
            $message,
            'ffp3:reserve-low'
        );
    }

    /**
     * Seuil opt-in (mm) : réserve basse si EauReserve > seuil. Null si non configuré.
     *
     * Priorité à la BDD de contrôle (ligne server-only GPIO 130) ; à défaut (repo absent,
     * ligne vide ou 0 = désactivé), repli sur `RESERVE_LOW_LEVEL_THRESHOLD` (`.env`).
     */
    private function resolveReserveLowThreshold(): ?float
    {
        $dbThreshold = $this->readReserveThresholdFromDb();
        if ($dbThreshold !== null) {
            return $dbThreshold;
        }

        $envThreshold = $this->operationalSettings?->optionalFloat('RESERVE_LOW_LEVEL_THRESHOLD');
        if ($envThreshold !== null) {
            return $envThreshold;
        }

        if (!isset($_ENV['RESERVE_LOW_LEVEL_THRESHOLD']) || (string) $_ENV['RESERVE_LOW_LEVEL_THRESHOLD'] === '') {
            return null;
        }

        return (float) $_ENV['RESERVE_LOW_LEVEL_THRESHOLD'];
    }

    /**
     * Lit le seuil réserve (mm) depuis la BDD (GPIO 130). Null si repo absent, ligne
     * vide/non numérique, ou valeur ≤ 0 (0 = désactivé, opt-in préservé).
     */
    private function readReserveThresholdFromDb(): ?float
    {
        if ($this->outputRepo === null) {
            return null;
        }

        try {
            $row = $this->outputRepo->findByGpio(self::RESERVE_LOW_THRESHOLD_GPIO);
        } catch (\Throwable $e) {
            $this->logger->warning('SystemHealthService: lecture seuil réserve (GPIO 130) impossible', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($row === null || !isset($row['state']) || $row['state'] === '' || !is_numeric($row['state'])) {
            return null;
        }

        $value = (float) $row['state'];

        return $value > 0 ? $value : null;
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

use App\Notification\NotificationCategory;
use App\Notification\Severity;
use App\Repository\OutputRepository;
use App\Repository\SensorReadRepository;
use App\Service\Availability\AvailabilityIncident;
use App\Service\Availability\AvailabilityNotifier;
use App\Service\Availability\ContactEvidenceSource;
use App\Service\Availability\WakeAssessment;
use App\Service\Availability\WakeScheduleModel;

/**
 * Service de surveillance de l'état de santé du système.
 * Permet de vérifier si le système est en ligne (données récentes) et d'alerter en cas de problème.
 * Peut être enrichi pour surveiller d'autres indicateurs (niveau d'eau, batterie, etc.).
 *
 * La supervision « plus aucune donnée » est suivie comme un INCIDENT de disponibilité
 * ({@see AvailabilityNotifier}) : un e-mail à la perte du flux, un à son rétablissement,
 * rien entre les deux — au lieu d'un rappel à chaque passage horaire du CRON.
 */
class SystemHealthService
{
    /** GPIO server-only (FFP3) : seuil réserve basse en mm (vide/0 = alerte désactivée). */
    private const RESERVE_LOW_THRESHOLD_GPIO = 130;

    /** Seule famille supervisée par ce service (tables de données FFP3). */
    private const FAMILY = 'FFP3';

    /**
     * @param SensorReadRepository      $sensorReadRepo Accès aux données capteurs
     * @param NotificationService       $notifier       Service de notification (e-mail)
     * @param LogService                $logger         Service de log
     * @param OutputRepository|null     $outputRepo     Lecture des seuils pilotés en BDD (optionnel)
     * @param AvailabilityNotifier|null $availability   Machine à états « un mail à la perte,
     *                                                  un mail au retour ». Absente → ancien
     *                                                  comportement (rappel à chaque passage)
     */
    public function __construct(
        private SensorReadRepository $sensorReadRepo,
        private NotificationService $notifier,
        private LogService $logger,
        private ?OutputRepository $outputRepo = null,
        private ?OperationalSettingsService $operationalSettings = null,
        private ?AvailabilityNotifier $availability = null,
        private ?ContactEvidenceSource $evidence = null,
        private ?WakeScheduleModel $wakeModel = null,
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
            $this->reportDataFlowDown(
                "Le système n'a enregistré aucune donnée de capteur. Veuillez vérifier la connexion ou le capteur.",
                Severity::Alert,
                function (): void {
                    $this->notifier->notifyNoSensorData();
                }
            );
            return;
        }

        $lastReadingTimestamp = strtotime($lastReadingDateStr);
        if ($lastReadingTimestamp === false) {
            // Format inattendu : on log et on considère le système offline
            $this->logger->error('Format de date invalide pour la dernière lecture', ['value' => $lastReadingDateStr]);
            $this->reportDataFlowDown(
                "La date de la dernière mesure est illisible (« {$lastReadingDateStr} ») : le système est "
                . 'considéré hors ligne. Veuillez intervenir.',
                Severity::Critical,
                function (): void {
                    $this->notifier->notifySystemOffline();
                }
            );
            return;
        }

        $now = time();

        // 6.59.0 : modèle de réveil attendu (réveils manqués) quand des preuves sont câblées ;
        // sinon seuil historique. Dans les deux cas, le rétablissement exige un contact
        // authentifié postérieur au dernier contact connu (incident du 06/11/2026 : faux
        // « Réception des données rétablie » à 19:40 quand le seuil nuit s'est allongé).
        $assessment = $this->assess();
        $offline = $assessment !== null && $assessment->lastContact !== null
            ? $assessment->isOffline()
            : ($now - $lastReadingTimestamp) > $maxOfflineSeconds;

        if ($offline) {
            $this->logger->critical('Le système semble hors ligne !');
            $message = $assessment !== null && $assessment->isOffline()
                ? sprintf(
                    "%d réveil(s) attendu(s) manqué(s) : aucune mesure reçue depuis %d minute(s).\n%s\n"
                    . 'Le système ne transmet plus de données. Veuillez intervenir (iot_contact_timeline pour la frise).',
                    $assessment->missedWakes,
                    (int) round(($now - $lastReadingTimestamp) / 60),
                    $assessment->describe()
                )
                : sprintf(
                    "Aucune mesure reçue depuis %d minute(s) (seuil : %d minute(s)).\n"
                    . 'Le système ne transmet plus de données. Veuillez intervenir.',
                    (int) round(($now - $lastReadingTimestamp) / 60),
                    (int) round($maxOfflineSeconds / 60)
                );
            $this->reportDataFlowDown(
                $message,
                Severity::Critical,
                function (): void {
                    $this->notifier->notifySystemOffline();
                },
                $lastReadingTimestamp
            );
        } else {
            $this->logger->info('Le système est en ligne.');
            $this->availability?->reportOnline(
                AvailabilityIncident::dataFlow(self::FAMILY),
                $this->lastAuthenticatedContact() ?? $lastReadingTimestamp
            );
        }
    }

    /** Évaluation « réveils manqués » FFP3, ou null sans source de preuves. */
    public function assess(): ?WakeAssessment
    {
        if ($this->evidence === null) {
            return null;
        }
        try {
            return ($this->wakeModel ?? new WakeScheduleModel())->assess(
                $this->evidence->recentContacts(self::FAMILY),
                $this->evidence->expectedPeriodSeconds(self::FAMILY)
            );
        } catch (\Throwable $e) {
            $this->logger->warning('SystemHealthService: preuves de contact illisibles', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function lastAuthenticatedContact(): ?int
    {
        try {
            return $this->evidence?->lastAuthenticatedContact(self::FAMILY);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Ouvre (une seule fois) l'incident « flux de données interrompu ».
     *
     * Subordination volontaire : si l'appareil est DÉJÀ signalé silencieux par
     * {@see DeviceHealthService} (incident heartbeat ouvert), l'alerte données ferait
     * doublon — même panne, deux e-mails. On se tait alors, et l'incident données reste
     * fermé : rien à clôturer quand l'appareil reviendra, c'est l'incident heartbeat qui
     * portera l'e-mail de rétablissement.
     *
     * @param callable(): void $legacyNotify Envoi historique, utilisé sans machine à états
     */
    private function reportDataFlowDown(string $message, Severity $severity, callable $legacyNotify, ?int $lastContactTs = null): void
    {
        if ($this->availability === null) {
            $legacyNotify();

            return;
        }

        if ($this->availability->isOffline(AvailabilityIncident::heartbeat(self::FAMILY))) {
            $this->logger->info(
                'SystemHealthService: appareil déjà signalé silencieux, alerte « données » supprimée (doublon).'
            );

            return;
        }

        $this->availability->reportOffline(
            AvailabilityIncident::dataFlow(self::FAMILY),
            $message . "\n\nCet e-mail ne sera pas répété : un second message vous préviendra dès que "
            . 'les données reprendront.',
            $severity,
            $lastContactTs
        );
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

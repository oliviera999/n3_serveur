<?php

declare(strict_types=1);

namespace App\Service\DerivedAlert;

use App\Notification\NotificationCategory;
use App\Notification\Severity;
use App\Repository\OutputRepository;
use App\Repository\SensorReadRepository;
use App\Service\LogService;
use App\Service\NotificationService;
use App\Service\OperationalSettingsService;

/**
 * Alertes FFP3 dérivées des données reçues au POST (Phase 2 arbitrage mails).
 *
 * Le serveur devient l'émetteur PRIMAIRE de ces alertes, jusqu'ici ESP-only
 * (cf. docs/ARCHITECTURE_MAILS_ARBITRAGE.md §3.2) :
 *  - TROP-PLEIN : `EauAquarium < limFlood` (GPIO 114, cm) avec la machine d'état
 *    anti-spam du firmware portée côté serveur ({@see FloodStateMachine} :
 *    debounce, cooldown, hystérésis de sortie) ;
 *  - CHAUFFAGE ON/OFF : transition de `etatHeat` entre deux lectures, avec
 *    `TempEau` et le seuil (GPIO 104) dans le message ;
 *  - MISE À JOUR FIRMWARE (OTA réussie / reflash) : changement de la colonne
 *    `version` entre deux lectures ({@see FirmwareUpdateDetector}) ;
 *  - REMPLISSAGE DÉMARRÉ / TERMINÉ : transition de `etatPompeTank` (pompe
 *    réserve) entre deux lectures — remplace les confirmations P3 du firmware ;
 *  - CAPTEUR ABSENT / FIGÉ (6.56.0) : EauAquarium vide sur les dernières mesures, ou
 *    même valeur répétée des heures ({@see SensorPresenceEvaluator}) — un e-mail à
 *    l'ouverture, un au rétablissement. Sans ce signal, un capteur muet rendait aveugles
 *    le contrôle des marées, le niveau bas et le trop-plein (incident 30/10/2026).
 *
 * NOURRISSAGE (fait / manqué / plafond) — GAP DOCUMENTÉ : non dérivable du POST
 * actuel. Depuis le contrat « compteur monotone » (serveur 6.0.0 / firmware 15.0),
 * ffp5cs poste `bouffePetits=0`/`bouffeGros=0` (champs plus lus), les GPIO 108/109
 * sont des compteurs de COMMANDES manuelles détenus par le serveur (sans ack de
 * consommation), et le POST n'échoit que les HEURES des créneaux (105-107), pas les
 * flags « fait ». Migrer ces alertes exigera un nouveau champ au POST (ex. flags
 * bouffeOk / cumul journalier) → à traiter avec la phase firmware (3/4). D'ici là,
 * l'ESP RESTE l'émetteur primaire du nourrissage (règle d'ordonnancement §4 : ne
 * jamais faire taire l'ESP sur une alerte que le serveur ne calcule pas).
 *
 * Appelé chaque minute par CronOrchestrator (bucket fréquent). L'état inter-runs
 * (machine flood, dernier état chauffage) est persisté en JSON via
 * {@see DerivedAlertStateStore}.
 */
class Ffp3DerivedAlertService
{
    // Ffp3DerivedAlertService n'étend PAS AbstractVitalsDerivedAlertService (DI et
    // dépendances distinctes) : les helpers partagés arrivent par traits plutôt que
    // par héritage, ce qui évite un changement de hiérarchie risqué.
    use BooleanTransitionDetectorTrait;
    use FloatCastTrait;

    /** GPIO 114 (FFP3) : limite trop-plein firmware, en CENTIMÈTRES en BDD. */
    private const LIM_FLOOD_GPIO = 114;
    /** GPIO 104 (FFP3) : seuil chauffage (°C). */
    private const HEATER_THRESHOLD_GPIO = 104;
    /** Conversion cm→mm (EauAquarium est en mm ; les seuils BDD sont en cm). */
    private const MM_PER_CM = 10.0;

    /** Défauts alignés sur le firmware (ffp5cs config_tasks.h). */
    private const DEFAULT_FLOOD_DEBOUNCE_SEC = 300;      // FLOOD_DEBOUNCE_MIN = 5
    private const DEFAULT_FLOOD_COOLDOWN_SEC = 3600;     // FLOOD_COOLDOWN_MIN = 60
    private const DEFAULT_FLOOD_HYST_CM = 2.0;           // FLOOD_HYST_CM
    private const DEFAULT_FLOOD_RESET_STABLE_SEC = 900;  // FLOOD_RESET_STABLE_MIN = 15

    /** Fraîcheur max (s) d'une lecture pour évaluer le trop-plein (au-delà, l'alerte « hors ligne » couvre). */
    private const DEFAULT_MAX_READING_AGE_SEC = 900;

    /** Capteurs suivis par l'alerte « absent / figé » : colonne => libellé. */
    private const MONITORED_SENSORS = [
        'EauAquarium' => 'niveau d\'eau de l\'aquarium (ultrason)',
    ];
    /** Lignes examinées pour l'alerte capteur (≈ 6 h au rythme du firmware). */
    private const SENSOR_WINDOW_READINGS = 60;
    private const DEFAULT_SENSOR_ABSENT_MIN_READINGS = 6;
    private const DEFAULT_SENSOR_FROZEN_MIN_READINGS = 12;
    private const DEFAULT_SENSOR_FROZEN_MIN_SECONDS = 7200;

    public function __construct(
        private SensorReadRepository $sensorReadRepo,
        private OutputRepository $outputRepo,
        private NotificationService $notifier,
        private LogService $logger,
        private DerivedAlertStateStore $stateStore,
        private ?\Closure $clock = null,
        private ?OperationalSettingsService $operationalSettings = null,
    ) {
    }

    public function run(): void
    {
        // getLastReadings(1) renvoie la dernière ligne sous forme associative.
        $reading = $this->sensorReadRepo->getLastReadings();
        if ($reading === []) {
            return;
        }

        $state = $this->stateStore->load();
        $now = $this->now();

        $this->checkFlood($reading, $state, $now);
        $this->checkHeaterTransition($reading, $state);
        $this->checkTankPumpTransition($reading, $state);
        $this->checkFirmwareUpdate($reading, $state);
        $this->checkSensorPresence($reading, $state, $now);

        $this->stateStore->save($state);
    }

    /**
     * Remplissage (pompe réserve) : transition de `etatPompeTank` entre lectures.
     * Reprend les confirmations « Remplissage démarré/terminé » du firmware (P3).
     *
     * @param array<string, mixed> $reading
     * @param array<string, mixed> $state
     */
    private function checkTankPumpTransition(array $reading, array &$state): void
    {
        $this->detectTransition($state, 'tankPump', $reading['etatPompeTank'] ?? null, function (bool $running) use ($reading): bool {
            $levelMm = $this->toFloatOrNull($reading['EauAquarium'] ?? null);
            $levelTxt = $levelMm !== null ? sprintf('%.0f mm', $levelMm) : 'inconnu';

            if ($running) {
                $subject = 'Remplissage démarré';
                $message = "La pompe de la réserve vient de démarrer (remplissage de l'aquarium).\n"
                    . "Niveau aquarium (distance capteur→surface) : {$levelTxt}.";
            } else {
                $subject = 'Remplissage terminé';
                $message = "La pompe de la réserve vient de s'arrêter (fin de remplissage).\n"
                    . "Niveau aquarium (distance capteur→surface) : {$levelTxt}.";
            }

            // Pas de clé d'anti-spam : dédup par transition (cf. checkHeaterTransition).
            return $this->notifier->sendAlert(
                Severity::Info,
                NotificationCategory::Hydraulic,
                'FFP3',
                $subject,
                $message
            );
        });
    }

    /**
     * Mise à jour firmware (OTA réussie / reflash) dérivée de la colonne `version`.
     * ffp5cs n'a pas de bootCount au POST : ce mail est le seul signal serveur de
     * fin d'OTA pour la famille FFP3.
     *
     * @param array<string, mixed> $reading
     * @param array<string, mixed> $state
     */
    private function checkFirmwareUpdate(array $reading, array &$state): void
    {
        $update = FirmwareUpdateDetector::detect($reading, $state);
        if ($update === null) {
            return;
        }

        $message = sprintf(
            "Le firmware de l'appareil FFP3 est passé de la version %s à la version %s.\n"
            . 'Mise à jour OTA réussie (ou reflash manuel) — le redémarrage associé est normal.',
            $update['from'],
            $update['to']
        );
        $this->notifier->sendAlert(
            Severity::Info,
            NotificationCategory::Lifecycle,
            'FFP3',
            'Firmware mis à jour',
            $message,
            'ffp3:fw-update:' . $update['to']
        );
    }

    /**
     * Capteur absent / figé : machine ok → absent|frozen → ok, un e-mail par transition
     * (latché sur envoi effectif, comme checkFlood). Évalué seulement sur une mesure fraîche :
     * un appareil silencieux relève de DeviceHealthService.
     *
     * @param array<string, mixed> $reading
     * @param array<string, mixed> $state
     */
    private function checkSensorPresence(array $reading, array &$state, int $now): void
    {
        $readingTs = strtotime((string) ($reading['reading_time'] ?? ''));
        if ($readingTs === false || ($now - $readingTs) > $this->intSetting('FLOOD_MAX_READING_AGE_SEC', self::DEFAULT_MAX_READING_AGE_SEC)) {
            return;
        }
        $minAbsent = $this->intSetting('SENSOR_ABSENT_MIN_READINGS', self::DEFAULT_SENSOR_ABSENT_MIN_READINGS);
        $frozenReadings = $this->intSetting('SENSOR_FROZEN_MIN_READINGS', self::DEFAULT_SENSOR_FROZEN_MIN_READINGS);
        $frozenSeconds = $this->intSetting('SENSOR_FROZEN_MIN_SECONDS', self::DEFAULT_SENSOR_FROZEN_MIN_SECONDS);

        foreach (self::MONITORED_SENSORS as $column => $label) {
            $rows = $this->sensorReadRepo->getRecentColumnValues($column, self::SENSOR_WINDOW_READINGS);
            if ($rows === []) {
                continue;
            }
            $result = SensorPresenceEvaluator::evaluate($rows, $minAbsent, $frozenReadings, $frozenSeconds);
            $previous = (array) ($state['sensors'][$column] ?? []);
            $previousStatus = (string) ($previous['status'] ?? SensorPresenceEvaluator::STATUS_OK);
            if ($result['status'] === $previousStatus) {
                continue;
            }

            $sent = $this->notifySensorTransition($column, $label, $previousStatus, $result);
            if ($sent) {
                $state['sensors'][$column] = [
                    'status' => $result['status'],
                    'since' => $result['since'],
                    'last_value' => $result['last_value'],
                    'changed_at' => $now,
                ];
                $this->logger->warning("Capteur {$column} : {$previousStatus} → {$result['status']}.");
            }
        }
    }

    /**
     * @param array{status: string, readings: int, since: string|null, last_value: float|null} $result
     */
    private function notifySensorTransition(string $column, string $label, string $previousStatus, array $result): bool
    {
        $last = $result['last_value'] !== null ? sprintf('%.0f mm', $result['last_value']) : 'aucune sur la période examinée';
        $consequence = 'Tant que la mesure est invalide, le serveur ne coupe plus la pompe sur un « problème de marées », '
            . "et le niveau bas, le remplissage automatique et le trop-plein (serveur et ESP32) sont aveugles.\n";

        if ($result['status'] === SensorPresenceEvaluator::STATUS_ABSENT) {
            return $this->notifier->sendAlert(
                Severity::Critical,
                NotificationCategory::Hydraulic,
                'FFP3',
                'Capteur ' . $column . ' absent',
                sprintf(
                    "Le capteur « %s » ne fournit plus de valeur : %d mesure(s) reçue(s) d'affilée sans %s, depuis le %s.\n"
                    . "Dernière valeur valide : %s.\n%s"
                    . 'Vérifier sur place le capteur (position, condensation, câble/connecteur) ; un redémarrage de l\'ESP32 ne suffit pas si l\'écho est perdu.',
                    $label,
                    $result['readings'],
                    $column,
                    (string) $result['since'],
                    $last,
                    $consequence
                ),
                'ffp3:sensor-absent:' . $column
            );
        }
        if ($result['status'] === SensorPresenceEvaluator::STATUS_FROZEN) {
            return $this->notifier->sendAlert(
                Severity::Critical,
                NotificationCategory::Hydraulic,
                'FFP3',
                'Capteur ' . $column . ' figé',
                sprintf(
                    "Le capteur « %s » renvoie exactement la même valeur (%s) sur %d mesures depuis le %s.\n"
                    . "Une vraie mesure varie avec les marées : valeur probablement répétée par le firmware faute d'écho, "
                    . "ou pompe aquarium arrêtée.\n%s"
                    . 'Vérifier le capteur et la pompe sur place.',
                    $label,
                    $last,
                    $result['readings'],
                    (string) $result['since'],
                    $consequence
                ),
                'ffp3:sensor-frozen:' . $column
            );
        }

        // Rétablissement (pas de clé d'anti-spam : dédup par transition).
        return $this->notifier->sendAlert(
            Severity::Info,
            NotificationCategory::Hydraulic,
            'FFP3',
            'Capteur ' . $column . ' rétabli',
            sprintf(
                'Le capteur « %s » fournit de nouveau des mesures variées (était : %s). Dernière valeur : %s.',
                $label,
                $previousStatus === SensorPresenceEvaluator::STATUS_ABSENT ? 'absent' : 'figé',
                $last
            )
        );
    }

    private function intSetting(string $key, int $default): int
    {
        return $this->operationalSettings?->int($key, $default) ?? (int) ($_ENV[$key] ?? $default);
    }

    /**
     * @param array<string, mixed>  $reading
     * @param array<string, mixed>  $state
     */
    private function checkFlood(array $reading, array &$state, int $now): void
    {
        $levelMm = $this->toFloatOrNull($reading['EauAquarium'] ?? null);
        if ($levelMm === null) {
            return;
        }

        // Lecture trop ancienne : appareil silencieux → couvert par DeviceHealthService,
        // ne pas évaluer le trop-plein sur une donnée périmée.
        $maxAge = $this->operationalSettings?->int('FLOOD_MAX_READING_AGE_SEC', self::DEFAULT_MAX_READING_AGE_SEC)
            ?? (int) ($_ENV['FLOOD_MAX_READING_AGE_SEC'] ?? self::DEFAULT_MAX_READING_AGE_SEC);
        $readingTs = strtotime((string) ($reading['reading_time'] ?? ''));
        if ($readingTs === false || ($now - $readingTs) > $maxAge) {
            return;
        }

        $limFloodCm = $this->readPositiveOutputFloat(self::LIM_FLOOD_GPIO);
        if ($limFloodCm === null) {
            return; // seuil non configuré : alerte désactivée
        }

        $limFloodMm = $limFloodCm * self::MM_PER_CM;
        $hystCm = $this->operationalSettings?->float('FLOOD_HYST_CM', self::DEFAULT_FLOOD_HYST_CM)
            ?? (float) ($_ENV['FLOOD_HYST_CM'] ?? self::DEFAULT_FLOOD_HYST_CM);
        $resetMm = ($limFloodCm + $hystCm) * self::MM_PER_CM;

        /** @var array{inFlood: bool, enterSinceTs: int, aboveResetSinceTs: int, lastEmailTs: int} $floodState */
        $floodState = array_merge(FloodStateMachine::initialState(), (array) ($state['flood'] ?? []));

        $decision = FloodStateMachine::evaluate(
            $floodState,
            $levelMm,
            $now,
            $limFloodMm,
            $resetMm,
            (int) ($this->operationalSettings?->int('FLOOD_DEBOUNCE_SEC', self::DEFAULT_FLOOD_DEBOUNCE_SEC)
                ?? (int) ($_ENV['FLOOD_DEBOUNCE_SEC'] ?? self::DEFAULT_FLOOD_DEBOUNCE_SEC)),
            (int) ($this->operationalSettings?->int('FLOOD_COOLDOWN_SEC', self::DEFAULT_FLOOD_COOLDOWN_SEC)
                ?? (int) ($_ENV['FLOOD_COOLDOWN_SEC'] ?? self::DEFAULT_FLOOD_COOLDOWN_SEC)),
            (int) ($this->operationalSettings?->int('FLOOD_RESET_STABLE_SEC', self::DEFAULT_FLOOD_RESET_STABLE_SEC)
                ?? (int) ($_ENV['FLOOD_RESET_STABLE_SEC'] ?? self::DEFAULT_FLOOD_RESET_STABLE_SEC)),
        );

        if ($decision === FloodStateMachine::DECISION_SEND_EMAIL) {
            $message = sprintf(
                "La distance capteur→surface de l'aquarium est descendue à %.0f mm (limite trop-plein %.0f mm).\n"
                . 'Risque de débordement : vérifier le système (pompe, siphon, capteur).',
                $levelMm,
                $limFloodMm
            );
            $sent = $this->notifier->sendAlert(
                Severity::Critical,
                NotificationCategory::Hydraulic,
                'FFP3',
                'Aquarium TROP PLEIN',
                $message,
                'ffp3:flood'
            );
            // Latch sur envoi effectif uniquement (parité Phase 0 firmware) : un envoi
            // filtré/refusé sera retenté au prochain tick (cooldown machine inchangé).
            if ($sent) {
                FloodStateMachine::markEmailSent($floodState, $now);
                $this->logger->warning("Alerte trop-plein envoyée (niveau {$levelMm} mm < {$limFloodMm} mm).");
            }
        } elseif ($decision === FloodStateMachine::DECISION_EXIT_FLOOD) {
            $this->logger->info('Sortie de l\'état trop-plein (niveau stabilisé au-dessus de l\'hystérésis).');
        }

        $state['flood'] = $floodState;
    }

    /**
     * @param array<string, mixed> $reading
     * @param array<string, mixed> $state
     */
    private function checkHeaterTransition(array $reading, array &$state): void
    {
        $this->detectTransition($state, 'heater', $reading['etatHeat'] ?? null, function (bool $heat) use ($reading): bool {
            $tempEau = $this->toFloatOrNull($reading['TempEau'] ?? null);
            $threshold = $this->readPositiveOutputFloat(self::HEATER_THRESHOLD_GPIO);

            $label = $heat ? 'ON' : 'OFF';
            $message = sprintf(
                "Le chauffage de l'aquarium vient de passer %s.\nTempérature de l'eau : %s°C (seuil : %s°C).",
                $label,
                $tempEau !== null ? sprintf('%.1f', $tempEau) : 'inconnue',
                $threshold !== null ? sprintf('%.1f', $threshold) : 'inconnu'
            );

            // Pas de clé d'anti-spam : la déduplication vient de la détection de
            // transition (un mail par bascule). Un cooldown P3 (6 h) avalerait les
            // bascules légitimes suivantes de la journée.
            return $this->notifier->sendAlert(
                Severity::Info,
                NotificationCategory::Environment,
                'FFP3',
                "Chauffage {$label}",
                $message
            );
        });
    }

    private function readPositiveOutputFloat(int $gpio): ?float
    {
        try {
            $row = $this->outputRepo->findByGpio($gpio);
        } catch (\Throwable $e) {
            $this->logger->warning("Ffp3DerivedAlertService: lecture GPIO {$gpio} impossible", [
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

    private function now(): int
    {
        return $this->clock !== null ? (int) ($this->clock)() : time();
    }
}

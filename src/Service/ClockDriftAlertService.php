<?php

declare(strict_types=1);

namespace App\Service;

use App\Notification\NotificationCategory;
use App\Notification\Severity;
use App\Repository\DeviceContactRepository;
use App\Service\Availability\WakeScheduleModel;

/**
 * Alerte DIAGNOSTIC (P4) de dérive d'horloge firmware : quand, sur 3 rafales consécutives, le
 * décalage `ts_received − heure serveur` dépasse `CLOCK_DRIFT_ALERT_SECONDS` (défaut 120 s).
 *
 * Source : `device_contacts` (POST et heartbeats, acceptés ou rejetés). Les contacts sont
 * regroupés en rafales ({@see WakeScheduleModel::bursts()}) ; une rafale est « désynchronisée »
 * si son décalage maximal absolu dépasse le seuil. Incident du 06/11/2026 : l'ESP32 WROOM se
 * réveillait avec une horloge en avance de 2 045 s (un cycle de veille compté deux fois) ; chaque
 * première requête signée de la rafale était rejetée 401 « X-Sig invalide » sans qu'aucune
 * alerte ne le dise.
 *
 * Anti-spam : clé `<famille>:clock-drift`, cooldown P4 (24 h) de l'AlertThrottler. Logique de
 * décision pure dans {@see evaluate()} (testable sans BDD).
 */
class ClockDriftAlertService
{
    public const CONSECUTIVE_BURSTS = 3;
    public const DEFAULT_THRESHOLD_SECONDS = 120;

    public function __construct(
        private DeviceContactRepository $contacts,
        private NotificationService $notifier,
        private LogService $logger,
        private ?OperationalSettingsService $settings = null,
        private ?WakeScheduleModel $model = null,
    ) {
    }

    /**
     * @param list<array{family: string, env: string}> $targets
     * @return int Nombre d'alertes émises
     */
    public function run(array $targets): int
    {
        $threshold = $this->settings?->int('CLOCK_DRIFT_ALERT_SECONDS', self::DEFAULT_THRESHOLD_SECONDS)
            ?? (int) ($_ENV['CLOCK_DRIFT_ALERT_SECONDS'] ?? self::DEFAULT_THRESHOLD_SECONDS);
        if ($threshold <= 0) {
            return 0;
        }
        $sent = 0;
        foreach ($targets as $target) {
            try {
                $rows = $this->contacts->recentClockOffsets($target['family'], $target['env'], 200);
            } catch (\Throwable $e) {
                $this->logger->warning('ClockDriftAlertService: lecture impossible', ['family' => $target['family'], 'error' => $e->getMessage()]);
                continue;
            }
            $verdict = $this->evaluate($rows, $threshold);
            if (!$verdict['alert']) {
                continue;
            }
            $family = strtoupper($target['family']);
            $message = sprintf(
                "L'horloge du module %s est désynchronisée : décalage firmware − serveur de %s sur les %d dernières rafales "
                . "(seuil %d s). Les requêtes signées HMAC risquent d'être rejetées (fenêtre SIG_VALID_WINDOW) : le module "
                . "doit resynchroniser son heure (NTP, ou X-Server-Ts renvoyé par le serveur) avant de signer.\n"
                . 'Décalages par rafale (s) : %s.',
                $family,
                $verdict['max_abs_offset'] >= 0 ? $verdict['max_abs_offset'] . ' s' : '?',
                self::CONSECUTIVE_BURSTS,
                $threshold,
                implode(', ', array_map(static fn (int $o): string => ($o > 0 ? '+' : '') . $o, $verdict['offsets']))
            );
            if ($this->notifier->sendAlert(
                Severity::Diagnostic,
                NotificationCategory::System,
                $family,
                'Horloge du module désynchronisée',
                $message,
                strtolower($target['family']) . ':clock-drift'
            )) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Décision pure : les {@see CONSECUTIVE_BURSTS} dernières rafales dépassent-elles toutes le seuil ?
     *
     * @param list<array{received_at: string, clock_offset_s: int, accepted?: int, kind?: string}> $rows Plus récent d'abord
     * @return array{alert: bool, bursts: int, offsets: list<int>, max_abs_offset: int}
     */
    public function evaluate(array $rows, int $thresholdSeconds): array
    {
        $model = $this->model ?? new WakeScheduleModel();
        $byTs = [];
        foreach ($rows as $row) {
            $ts = strtotime($row['received_at']);
            if ($ts === false) {
                continue;
            }
            $byTs[$ts][] = (int) $row['clock_offset_s'];
        }
        $bursts = $model->bursts(array_keys($byTs));
        $recent = array_slice($bursts, -self::CONSECUTIVE_BURSTS);
        $offsets = [];
        foreach ($recent as $burst) {
            $worst = 0;
            foreach ($byTs as $ts => $list) {
                if ($ts < $burst['start'] || $ts > $burst['end']) {
                    continue;
                }
                foreach ($list as $o) {
                    if (abs($o) > abs($worst)) {
                        $worst = $o;
                    }
                }
            }
            $offsets[] = $worst;
        }
        $alert = count($recent) >= self::CONSECUTIVE_BURSTS
            && count(array_filter($offsets, static fn (int $o): bool => abs($o) > $thresholdSeconds)) === count($offsets);
        $maxAbs = $offsets === [] ? -1 : max(array_map('abs', $offsets));

        return ['alert' => $alert, 'bursts' => count($bursts), 'offsets' => $offsets, 'max_abs_offset' => $maxAbs];
    }
}

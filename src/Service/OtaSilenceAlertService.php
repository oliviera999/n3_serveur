<?php

declare(strict_types=1);

namespace App\Service;

use App\Notification\NotificationCategory;
use App\Notification\Severity;
use App\Repository\OtaEventRepository;
use App\Service\Availability\ContactEvidenceSource;
use App\Service\Availability\WakeScheduleModel;

/**
 * Alerte « module muet après téléchargement OTA » : un binaire a été servi EN ENTIER à un module
 * ({@see OtaEventRepository::lastCompleteBinaryDownload()}) et aucun contact n'est revenu dans les
 * `OTA_SILENT_AFTER_DOWNLOAD_WAKEUPS` (défaut 2) réveils attendus qui suivent.
 *
 * C'est précisément le scénario du 06/11/2026 : si le téléchargement de la 15.33 avait été
 * journalisé, la perte de contact de 16:40 aurait été attribuable à l'OTA dès 17:50.
 *
 * Canaux surveillés par famille : ffp3 → esp32-wroom / esp32-s3 (et leurs variantes), n3pp → n3pp*,
 * msp1 → msp*. Une alerte par téléchargement (clé anti-spam incluant l'horodatage du téléchargement).
 */
class OtaSilenceAlertService
{
    /** @var array<string, list<string>> */
    public const CHANNELS_BY_FAMILY = [
        'ffp3' => ['esp32-wroom', 'esp32-wroom-beta', 'esp32-s3', 'esp32-s3-test', 'esp32-wroom-universal', 'esp32-s3-universal', 'esp32-s3-carrier'],
        'n3pp' => ['n3pp', 'n3pp-test', 'n3pp-universal', 'n3pp-universal-test'],
        'msp1' => ['msp', 'msp-test', 'msp-universal', 'msp-universal-test'],
    ];

    public function __construct(
        private OtaEventRepository $otaEvents,
        private ContactEvidenceSource $evidence,
        private NotificationService $notifier,
        private LogService $logger,
        private ?OperationalSettingsService $settings = null,
        private ?WakeScheduleModel $model = null,
    ) {
    }

    /**
     * @param list<string> $families
     * @return int Alertes émises
     */
    public function run(array $families = ['ffp3', 'n3pp', 'msp1'], ?int $now = null): int
    {
        $now ??= time();
        $wakeups = $this->settings?->int('OTA_SILENT_AFTER_DOWNLOAD_WAKEUPS', 2)
            ?? (int) ($_ENV['OTA_SILENT_AFTER_DOWNLOAD_WAKEUPS'] ?? 2);
        $wakeups = max(1, $wakeups);
        $sent = 0;
        foreach ($families as $family) {
            $verdict = $this->evaluate($family, $wakeups, $now);
            if ($verdict === null || !$verdict['alert']) {
                continue;
            }
            $upper = strtoupper($family);
            $message = sprintf(
                "Le module %s a téléchargé EN ENTIER un binaire OTA (%s, canal %s, %d octets, IP %s%s) à %s et n'a plus "
                . "donné signe de vie depuis : %d réveil(s) attendu(s) manqué(s) (période %d min). Dernier contact : %s.\n"
                . 'Causes probables : image qui ne démarre pas (rollback attendu si CONFIG_BOOTLOADER_APP_ROLLBACK_ENABLE et '
                . 'image non validée), flash interrompu, ou boot sans réseau. Vérifier iot_contact_timeline ; si le module ne '
                . 'revient pas au prochain réveil, mettre le canal en pause (OTA_PAUSED_CHANNELS) avant toute autre publication.',
                $upper,
                $verdict['download']['path'] ?? 'firmware.bin',
                $verdict['download']['channel'],
                $verdict['download']['bytes_sent'],
                $verdict['download']['ip'] ?? '?',
                isset($verdict['download']['board']) && $verdict['download']['board'] !== null ? ', carte ' . $verdict['download']['board'] : '',
                $verdict['download']['occurred_at'],
                $verdict['missed_wakes'],
                (int) round($verdict['period'] / 60),
                $verdict['last_contact'] !== null ? date('Y-m-d H:i:s', $verdict['last_contact']) : 'inconnu'
            );
            if ($this->notifier->sendAlert(
                Severity::Critical,
                NotificationCategory::Availability,
                $upper,
                'Module muet après téléchargement OTA',
                $message,
                $family . ':ota-silent:' . substr(md5($verdict['download']['occurred_at'] . $verdict['download']['channel']), 0, 8)
            )) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * @return array{alert: bool, download: array<string, mixed>, last_contact: ?int, missed_wakes: int, period: int}|null
     *         null si aucun téléchargement complet récent (48 h)
     */
    public function evaluate(string $family, int $wakeups, ?int $now = null): ?array
    {
        $now ??= time();
        $channels = self::CHANNELS_BY_FAMILY[strtolower($family)] ?? [];
        if ($channels === []) {
            return null;
        }
        try {
            $download = $this->otaEvents->lastCompleteBinaryDownload($channels, date('Y-m-d H:i:s', $now - 48 * 3600));
        } catch (\Throwable $e) {
            $this->logger->warning('OtaSilenceAlertService: lecture ota_events impossible', ['error' => $e->getMessage()]);

            return null;
        }
        if ($download === null) {
            return null;
        }
        $downloadTs = (int) strtotime($download['occurred_at']);
        $contacts = $this->evidence->recentContacts($family);
        $after = array_values(array_filter($contacts, static fn (int $t): bool => $t > $downloadTs + 60));
        $lastContact = $contacts === [] ? null : max($contacts);
        $period = ($this->model ?? new WakeScheduleModel())
            ->assess($contacts, $this->evidence->expectedPeriodSeconds($family), $now)->period;
        $margin = WakeScheduleModel::FIXED_MARGIN_SECONDS + (int) round($period * WakeScheduleModel::RELATIVE_MARGIN);
        $elapsed = $now - $downloadTs;
        $missed = $elapsed > $period + $margin ? (int) floor(($elapsed - $margin) / $period) : 0;

        return [
            'alert' => $after === [] && $missed >= $wakeups,
            'download' => $download,
            'last_contact' => $lastContact,
            'missed_wakes' => $missed,
            'period' => $period,
        ];
    }
}

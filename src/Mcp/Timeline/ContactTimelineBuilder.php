<?php

declare(strict_types=1);

namespace App\Mcp\Timeline;

use App\Config\TableConfig;
use App\Mcp\Support\ActiveAlertsReader;
use App\Mcp\Support\GalleryReader;
use App\Repository\DeviceContactRepository;
use App\Repository\DeviceEventRepository;
use App\Repository\McpReadRepository;
use App\Repository\OtaEventRepository;
use App\Repository\OtaReleaseRepository;
use App\Service\Availability\WakeAssessment;
use App\Service\Availability\WakeScheduleModel;
use App\Service\OfflineThresholdResolver;
use App\Service\OtaChannelPolicy;
use App\Service\OtaSilenceAlertService;

/**
 * Construit la FRISE DES CONTACTS d'un module (outil `iot_contact_timeline`) en fusionnant, sur
 * une même échelle de temps (heure serveur, APP_TIMEZONE) :
 *  - les POST acceptés (table de mesures, regroupés en rafales) et tous les contacts journalisés
 *    dans `device_contacts` (acceptés / rejetés, motif, mode d'auth, décalage d'horloge) ;
 *  - les heartbeats (uptime, reboots, raison de boot, partition, état OTA, mode sans échec) ;
 *  - les événements OTA côté serveur (`ota_events` : manifeste consulté, binaire servi, complet ou
 *    non, pause / canari), les publications (`ota_releases`) et les événements rapportés par le
 *    firmware (`device_events` : check / start / progress / done / fail / rollback / marked_valid,
 *    journal de bord, boot) ;
 *  - les photos de la caméra du même site (preuve indirecte que le réseau du site fonctionne) ;
 *  - les notifications envoyées et les alertes en cours ;
 *  - les erreurs serveur regroupées.
 *
 * Puis : dernier contact, prochain réveil attendu ({@see WakeScheduleModel}), classification de la
 * cause ({@see ContactLossClassifier}) et liste de ce qui reste invérifiable à distance.
 *
 * Lecture seule : exécuté sous `ReadOnlyTransaction` ; chaque source est isolée (table absente
 * avant migration → bloc vide, jamais une erreur).
 */
final class ContactTimelineBuilder
{
    /** Entrées maximales dans la frise (les POST sont déjà regroupés en rafales). */
    public const MAX_ENTRIES = 600;

    /** @var array<string, string> Famille → galerie caméra du même site. */
    private const CAMERA_BY_FAMILY = ['ffp3' => 'ffp3', 'n3pp' => 'n3pp', 'msp1' => 'msp1'];

    /** @var array<string, list<string>> Famille → cibles `ota_releases`. */
    private const RELEASE_TARGETS = [
        'ffp3' => ['ffp5'],
        'n3pp' => ['n3pp', 'n3pp-test', 'n3pp-universal', 'n3pp-universal-test'],
        'msp1' => ['msp', 'msp-test', 'msp-universal', 'msp-universal-test'],
    ];

    public function __construct(
        private McpReadRepository $read,
        private DeviceContactRepository $contacts,
        private OtaEventRepository $otaEvents,
        private OtaReleaseRepository $releases,
        private DeviceEventRepository $deviceEvents,
        private GalleryReader $gallery,
        private ActiveAlertsReader $alerts,
        private OfflineThresholdResolver $thresholds,
        private ?WakeScheduleModel $model = null,
        private ?ContactLossClassifier $classifier = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(string $family, string $env, \DateTimeImmutable $from, \DateTimeImmutable $to, ?int $now = null): array
    {
        $now ??= time();
        $fromSql = $from->format('Y-m-d H:i:s');
        $toSql = $to->format('Y-m-d H:i:s');
        $entries = [];
        $notes = [];

        // 1. POST acceptés (table de mesures) → rafales.
        $rows = $this->safe(fn (): array => $this->read->readingTimesBetween($family, $fromSql, $toSql, 5000), []);
        $postTimes = [];
        $versions = [];
        foreach ($rows as $row) {
            $ts = strtotime((string) ($row['reading_time'] ?? ''));
            if ($ts !== false) {
                $postTimes[] = $ts;
                if (isset($row['version']) && $row['version'] !== '') {
                    $versions[$ts] = (string) $row['version'];
                }
            }
        }
        $model = $this->model ?? new WakeScheduleModel();
        $lastVersion = null;
        foreach ($model->bursts($postTimes) as $burst) {
            $burstVersions = [];
            foreach ($versions as $ts => $v) {
                if ($ts >= $burst['start'] && $ts <= $burst['end']) {
                    $burstVersions[$v] = true;
                }
            }
            $lastVersion = $burstVersions !== [] ? array_key_last($burstVersions) : $lastVersion;
            $entries[] = [
                'at' => date('Y-m-d H:i:s', $burst['start']),
                'until' => date('Y-m-d H:i:s', $burst['end']),
                'type' => 'post_burst',
                'label' => sprintf('Rafale de %d POST acceptés (%s)', $burst['count'], implode(', ', array_keys($burstVersions)) ?: 'version inconnue'),
            ];
        }

        // 2. Journal des contacts (acceptés + rejetés).
        $contactRows = $this->safe(fn (): array => $this->contacts->between($family, $env, $fromSql, $toSql, 5000), []);
        $rejected = [];
        foreach ($contactRows as $c) {
            if ((int) $c['accepted'] === 1 && $c['kind'] === 'post') {
                continue; // déjà représenté par la rafale de mesures
            }
            $entries[] = [
                'at' => (string) $c['received_at'],
                'type' => (int) $c['accepted'] === 1 ? 'contact_ok' : 'contact_rejected',
                'label' => sprintf(
                    '%s %s%s%s',
                    (int) $c['accepted'] === 1 ? 'Contact accepté' : 'Requête REJETÉE',
                    (string) $c['kind'],
                    $c['reject_reason'] !== null ? ' (' . $c['reject_reason'] . ')' : ' (' . ($c['auth_mode'] ?? 'auth ?') . ')',
                    $c['clock_offset_s'] !== null ? sprintf(', horloge firmware %+d s', (int) $c['clock_offset_s']) : ''
                ),
                'http_status' => (int) $c['http_status'],
                'clock_offset_s' => $c['clock_offset_s'] !== null ? (int) $c['clock_offset_s'] : null,
            ];
            if ((int) $c['accepted'] === 0) {
                $rejected[] = $c;
            }
        }
        if ($contactRows === []) {
            $notes[] = 'Aucune ligne dans device_contacts sur la fenêtre : journal activé en 6.59.0 (migration 2026_11_contact_diagnostics.sql) — les rejets antérieurs ne sont visibles que dans les journaux texte.';
        }

        // 3. Heartbeats.
        $heartbeats = $this->safe(fn (): array => $this->read->heartbeatsBetween($family, $fromSql, $toSql, 2000), []);
        $hbTimes = [];
        $crashReasons = [];
        $firstReboots = null;
        $lastReboots = null;
        $lastHb = null;
        foreach ($heartbeats as $hb) {
            $ts = strtotime((string) ($hb['reading_time'] ?? ''));
            if ($ts === false) {
                continue;
            }
            $hbTimes[] = $ts;
            $lastHb = $hb;
            $reboots = isset($hb['reboots']) ? (int) $hb['reboots'] : null;
            $firstReboots ??= $reboots;
            $lastReboots = $reboots ?? $lastReboots;
            $rst = isset($hb['resetReason']) ? (string) $hb['resetReason'] : null;
            if ($rst !== null && in_array($rst, ['PANIC', 'INT_WDT', 'TASK_WDT', 'WDT'], true)) {
                $crashReasons[] = $rst;
            }
            $entries[] = [
                'at' => (string) $hb['reading_time'],
                'type' => 'heartbeat',
                'label' => sprintf(
                    'Heartbeat : uptime %s, reboots %s%s%s%s%s',
                    self::dur((int) ($hb['uptime'] ?? 0)),
                    $reboots ?? '?',
                    $rst !== null ? ', boot ' . $rst : '',
                    isset($hb['otaState']) && $hb['otaState'] !== null ? ', OTA ' . $hb['otaState'] . (isset($hb['partition']) && $hb['partition'] !== null ? ' sur ' . $hb['partition'] : '') : '',
                    isset($hb['safeMode']) && (int) $hb['safeMode'] === 1 ? ', MODE SANS ÉCHEC' : '',
                    isset($hb['clockOffsetS']) && $hb['clockOffsetS'] !== null ? sprintf(', horloge %+d s', (int) $hb['clockOffsetS']) : ''
                ),
            ];
        }

        // 4. OTA serveur : événements servis + publications.
        $channels = OtaSilenceAlertService::CHANNELS_BY_FAMILY[$family] ?? [];
        if ($family === 'ffp3') {
            $channels[] = OtaChannelPolicy::ROOT_CHANNEL;
        }
        $otaRows = $this->safe(fn (): array => $this->otaEvents->between($channels, $fromSql, $toSql, 2000), []);
        foreach ($otaRows as $o) {
            $entries[] = [
                'at' => (string) $o['occurred_at'],
                'type' => 'ota_server',
                'label' => sprintf(
                    'OTA serveur : %s %s → HTTP %d%s%s%s%s',
                    $o['kind'] === 'bin' ? 'binaire' : ($o['kind'] === 'metadata' ? 'manifeste' : 'fichier'),
                    (string) $o['path'],
                    (int) $o['http_status'],
                    $o['kind'] === 'bin' ? sprintf(', %d/%s octets, %s', (int) $o['bytes_sent'], $o['file_size'] ?? '?', (int) $o['complete'] === 1 ? 'COMPLET' : 'partiel') : '',
                    $o['board'] !== null ? ', carte ' . $o['board'] : '',
                    $o['ip'] !== null ? ', IP ' . $o['ip'] : '',
                    $o['note'] !== null ? ', ' . $o['note'] : ''
                ),
            ];
        }
        $releaseRows = $this->safe(fn (): array => $this->releases->between(self::RELEASE_TARGETS[$family] ?? [], $fromSql, $toSql, 200), []);
        foreach ($releaseRows as $r) {
            $entries[] = [
                'at' => (string) $r['published_at'],
                'type' => 'ota_release',
                'label' => sprintf('Publication OTA %s %s/%s version %s%s%s', (string) $r['target'], (string) $r['channel'], (string) $r['board'], (string) $r['version'], $r['size'] !== null ? sprintf(' (%d octets)', (int) $r['size']) : '', $r['author'] !== null ? ' par ' . $r['author'] : ''),
            ];
        }

        // 5. Événements rapportés par le firmware.
        $fwRows = $this->safe(fn (): array => $this->deviceEvents->between($family, $env, $fromSql, $toSql, 2000), []);
        $fwOtaEvents = [];
        $wifiFails = 0;
        foreach ($fwRows as $f) {
            if ($f['source'] === DeviceEventRepository::SOURCE_OTA_REPORT) {
                $fwOtaEvents[] = (string) $f['event'];
            }
            if (str_starts_with((string) $f['event'], 'wifi_fail') || str_starts_with((string) $f['event'], 'wifi_lost')) {
                $wifiFails++;
            }
            $entries[] = [
                'at' => (string) $f['received_at'],
                'type' => 'firmware_' . $f['source'],
                'label' => sprintf('Firmware (%s) : %s%s%s', (string) $f['source'], (string) $f['event'], $f['detail'] !== null ? ' — ' . $f['detail'] : '', $f['device_ts'] !== null && (int) $f['device_ts'] > 0 ? ' [horloge firmware ' . date('H:i:s', (int) $f['device_ts']) . ']' : ''),
            ];
        }

        // 6. Caméra du même site.
        $slug = self::CAMERA_BY_FAMILY[$family] ?? null;
        $camTimes = [];
        if ($slug !== null) {
            foreach ($this->safe(fn (): array => $this->gallery->entries($slug), []) as $photo) {
                $ts = (int) $photo['ts'];
                if ($ts >= $from->getTimestamp() && $ts <= $to->getTimestamp()) {
                    $camTimes[] = $ts;
                }
            }
            sort($camTimes);
            // Les photos arrivent toutes les 1,5 à 10 min : on les regroupe en plages pour ne pas noyer la frise.
            foreach ($model->bursts($camTimes) as $b) {
                $entries[] = [
                    'at' => date('Y-m-d H:i:s', $b['start']),
                    'until' => date('Y-m-d H:i:s', $b['end']),
                    'type' => 'camera',
                    'label' => sprintf('Caméra %s : %d photo(s) reçue(s) (réseau du site actif)', $slug, $b['count']),
                ];
            }
        }

        // 7. Notifications + erreurs serveur.
        $notifications = $this->safe(fn (): array => $this->read->notificationLog($fromSql, $toSql, $family, null, 200), []);
        foreach ($notifications as $n) {
            $entries[] = ['at' => (string) $n['sent_at'], 'type' => 'notification', 'label' => sprintf('E-mail %s [%s] %s', (string) $n['alert_key'], (string) $n['severity'], (string) $n['subject'])];
        }
        $errors = $this->safe(fn (): array => $this->read->errorAlerts($fromSql, $toSql, 50), []);
        foreach ($errors as $err) {
            $entries[] = ['at' => (string) $err['last_seen'], 'type' => 'server_error', 'label' => sprintf('Erreur serveur ×%d : %s', (int) $err['occurrences'], mb_substr((string) $err['normalized_message'], 0, 160))];
        }

        usort($entries, static fn (array $a, array $b): int => strcmp($a['at'], $b['at']));
        $truncated = count($entries) > self::MAX_ENTRIES;
        if ($truncated) {
            $entries = array_slice($entries, -self::MAX_ENTRIES);
        }

        // 8. Dernier contact, réveil attendu, classification.
        $allContacts = array_merge($postTimes, $hbTimes);
        foreach ($contactRows as $c) {
            if ((int) $c['accepted'] === 1) {
                $ts = strtotime((string) $c['received_at']);
                if ($ts !== false) {
                    $allContacts[] = $ts;
                }
            }
        }
        $period = $this->safe(fn (): int => $this->thresholds->expectedSleepSeconds(strtoupper($family)), 600);
        $assessment = $model->assess($allContacts, $period, $now);
        $lastContact = $assessment->lastContact;
        $lastAuthenticated = $this->safe(fn (): ?int => $this->contacts->lastAcceptedContact($family, true, $env), null);

        $evidence = $this->evidence($assessment, $now, $contactRows, $rejected, $heartbeats, $lastHb, $crashReasons, $firstReboots, $lastReboots, $otaRows, $releaseRows, $fwOtaEvents, $wifiFails, $camTimes, $errors, $lastVersion, $lastContact);
        $classification = ($this->classifier ?? new ContactLossClassifier())->classify($evidence);

        $activeAlerts = array_values(array_filter($this->safe(fn (): array => $this->alerts->active(), []), static fn (array $a): bool => ($a['family'] ?? '') === $family));

        return [
            'family' => $family,
            'window' => ['from' => $fromSql, 'to' => $toSql, 'timezone' => date_default_timezone_get()],
            'last_contact' => $lastContact !== null ? date('Y-m-d H:i:s', $lastContact) : null,
            'last_authenticated_contact' => $lastAuthenticated !== null ? date('Y-m-d H:i:s', $lastAuthenticated) : null,
            'silence_seconds' => $lastContact !== null ? max(0, $now - $lastContact) : null,
            'expected_wake' => $assessment->toArray(),
            'firmware_version_seen' => $lastVersion,
            'counts' => [
                'post_bursts' => count($model->bursts($postTimes)),
                'posts' => count($postTimes),
                'contacts_journaled' => count($contactRows),
                'rejected' => count($rejected),
                'heartbeats' => count($heartbeats),
                'ota_server_events' => count($otaRows),
                'ota_releases' => count($releaseRows),
                'firmware_events' => count($fwRows),
                'camera_photos' => count($camTimes),
                'notifications' => count($notifications),
            ],
            'classification' => $classification,
            'active_alerts' => $activeAlerts,
            'timeline' => $entries,
            'timeline_truncated' => $truncated,
            'notes' => array_merge($notes, [
                'Heures en heure serveur (' . date_default_timezone_get() . ') ; le site physique est à Casablanca (UTC+1 l\'hiver, comme Paris).',
                'Les POST acceptés sont regroupés en rafales ; les requêtes rejetées sont listées une à une avec leur motif.',
            ]),
        ];
    }

    /**
     * Preuves pour {@see ContactLossClassifier} (publique pour les tests).
     *
     * @param list<array<string, mixed>> $contactRows
     * @param list<array<string, mixed>> $rejected
     * @param list<array<string, mixed>> $heartbeats
     * @param array<string, mixed>|null  $lastHb
     * @param list<string>               $crashReasons
     * @param list<array<string, mixed>> $otaRows
     * @param list<array<string, mixed>> $releaseRows
     * @param list<string>               $fwOtaEvents
     * @param list<int>                  $camTimes
     * @param list<array<string, mixed>> $errors
     * @return array<string, mixed>
     */
    public function evidence(
        WakeAssessment $assessment,
        int $now,
        array $contactRows,
        array $rejected,
        array $heartbeats,
        ?array $lastHb,
        array $crashReasons,
        ?int $firstReboots,
        ?int $lastReboots,
        array $otaRows,
        array $releaseRows,
        array $fwOtaEvents,
        int $wifiFails,
        array $camTimes,
        array $errors,
        ?string $lastVersion,
        ?int $lastContact
    ): array {
        $loss = $lastContact ?? $now;
        $after = static fn (string $date): bool => (int) strtotime($date) > $loss;

        // Dernière rafale journalisée (contacts de toute nature dans les 10 min précédant le dernier contact).
        $burstReq = 0;
        $burstRejected = 0;
        $burstSigInvalid = 0;
        $burstMaxOffset = 0;
        $requestsAfter = 0;
        $rejectedAfter = 0;
        foreach ($contactRows as $c) {
            $ts = (int) strtotime((string) $c['received_at']);
            if ($ts > $loss) {
                $requestsAfter++;
                if ((int) $c['accepted'] === 0) {
                    $rejectedAfter++;
                }
                continue;
            }
            if ($ts >= $loss - 600) {
                $burstReq++;
                if ((int) $c['accepted'] === 0) {
                    $burstRejected++;
                    if (($c['reject_reason'] ?? '') === 'signature_invalid') {
                        $burstSigInvalid++;
                    }
                }
                if ($c['clock_offset_s'] !== null) {
                    $burstMaxOffset = max($burstMaxOffset, abs((int) $c['clock_offset_s']));
                }
            }
        }

        $releaseBefore = null;
        $releaseVersion = null;
        foreach ($releaseRows as $r) {
            $ts = (int) strtotime((string) $r['published_at']);
            if ($ts <= $loss && ($releaseBefore === null || $loss - $ts < $releaseBefore)) {
                $releaseBefore = $loss - $ts;
                $releaseVersion = (string) $r['version'];
            }
        }
        $binBefore = null;
        $binComplete = false;
        $metaBefore = null;
        foreach ($otaRows as $o) {
            $ts = (int) strtotime((string) $o['occurred_at']);
            if ((int) $o['http_status'] >= 400) {
                continue;
            }
            if ($o['kind'] === 'bin' && $ts <= $loss + 300 && ($binBefore === null || $loss - $ts < $binBefore)) {
                $binBefore = $loss - $ts;
                $binComplete = (int) $o['complete'] === 1;
            }
            if ($o['kind'] === 'metadata' && $ts <= $loss && ($metaBefore === null || $loss - $ts < $metaBefore)) {
                $metaBefore = $loss - $ts;
            }
        }

        $camBefore = count(array_filter($camTimes, static fn (int $t): bool => $t <= $loss));
        $camAfter = count(array_filter($camTimes, static fn (int $t): bool => $t > $loss + 60));

        $errorsAfter = 0;
        foreach ($errors as $err) {
            if ($after((string) $err['last_seen'])) {
                $errorsAfter += (int) $err['occurrences'];
            }
        }

        return [
            'now' => $now,
            'last_contact' => $lastContact,
            'silence_seconds' => $lastContact !== null ? max(0, $now - $lastContact) : 0,
            'period' => $assessment->period,
            'missed_wakes' => $assessment->missedWakes,
            'offline' => $assessment->isOffline(),
            'requests_after_loss' => $requestsAfter,
            'rejected_after_loss' => $rejectedAfter,
            'last_burst' => ['requests' => $burstReq, 'rejected' => $burstRejected, 'signature_invalid' => $burstSigInvalid, 'max_abs_offset' => $burstMaxOffset],
            'ota' => [
                'events_journaled' => $otaRows !== [],
                'release_before_loss_seconds' => $releaseBefore,
                'release_version' => $releaseVersion,
                'metadata_fetch_before_loss_seconds' => $metaBefore,
                'bin_download_before_loss_seconds' => $binBefore,
                'bin_complete' => $binComplete,
                'firmware_events' => $fwOtaEvents,
            ],
            'heartbeat' => [
                'last_reset_reason' => $lastHb !== null && isset($lastHb['resetReason']) ? $lastHb['resetReason'] : null,
                'crash_reasons' => $crashReasons,
                'reboots_delta' => $firstReboots !== null && $lastReboots !== null ? max(0, $lastReboots - $firstReboots) : null,
                'safe_mode' => $lastHb !== null && isset($lastHb['safeMode']) && (int) $lastHb['safeMode'] === 1,
                'last_uptime' => $lastHb !== null && isset($lastHb['uptime']) ? (int) $lastHb['uptime'] : null,
                'last_ota_state' => $lastHb !== null && isset($lastHb['otaState']) ? $lastHb['otaState'] : null,
            ],
            'camera' => ['had_uploads_before' => $camBefore > 0, 'uploads_after_loss' => $camAfter],
            'server' => ['errors_5xx_after_loss' => $errorsAfter, 'cron_late' => false, 'other_devices_contacting' => $camAfter > 0],
            'wifi' => ['fail_events' => $wifiFails, 'last_rssi' => $lastHb !== null && isset($lastHb['rssi']) && $lastHb['rssi'] !== null ? (int) $lastHb['rssi'] : null],
            'firmware' => ['seen_version' => $lastVersion, 'published_version' => $releaseVersion],
        ];
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @param T $fallback
     * @return T
     */
    private function safe(callable $callback, mixed $fallback): mixed
    {
        try {
            return $callback();
        } catch (\Throwable) {
            return $fallback;
        }
    }

    private static function dur(int $seconds): string
    {
        if ($seconds < 3600) {
            return (int) round($seconds / 60) . ' min';
        }
        if ($seconds < 86400) {
            return sprintf('%.1f h', $seconds / 3600);
        }

        return sprintf('%.1f j', $seconds / 86400);
    }
}

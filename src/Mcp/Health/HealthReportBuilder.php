<?php

declare(strict_types=1);

namespace App\Mcp\Health;

use App\Config\GalleryConfig;
use App\Config\PglConfig;
use App\Config\SensorFieldMap;
use App\Config\TableConfig;
use App\Config\Version;
use App\Mcp\McpConfig;
use App\Mcp\Support\EnvironmentScope;
use App\Mcp\Support\FieldCatalog;
use App\Mcp\Timeline\ContactTimelineBuilder;
use App\Mcp\Tools\OverviewTool;
use App\Mcp\Tools\SensorHealthTool;
use App\Repository\McpHealthRepository;
use App\Repository\McpOAuthRepository;
use App\Repository\McpReadRepository;
use App\Service\GalleryNormalizedStore;
use App\Service\OfflineThresholdResolver;
use App\Util\GalleryTimestamp;
use PDO;

/**
 * Construit le rapport de santé du site (outil `iot_health_report`, commande CRON
 * {@see \App\Command\HealthReportCommand}, endpoint `GET /health/report.json`).
 *
 * Contenu : version applicative et PHP, latence et version BDD, taille des tables
 * principales, CRON (dernier passage et retards), appareils (en ligne / hors ligne, dernier
 * heartbeat, seuil, firmware) pour chaque environnement de PRODUCTION lisible
 * (MCP_ALLOWED_ENVS ∩ {prod, s3}), erreurs et notifications sur 24 h et 7 j, dernière photo
 * par galerie, espace disque, `var/cache` inscriptible, expiration du certificat TLS, connexions MCP actives ; puis un
 * statut global calculé par {@see HealthRules} et un résumé d'une ligne.
 *
 * Lecture seule : aucune écriture (le stockage est fait par la commande CRON). Chaque bloc est
 * isolé : une source indisponible (table absente, disque illisible, TLS injoignable) est
 * signalée dans son bloc sans faire échouer le rapport. Aucun secret ni donnée personnelle.
 */
final class HealthReportBuilder
{
    /**
     * Profondeur d'analyse des capteurs FFP3 (muet / figé) : la MÊME fenêtre et le même calcul
     * que `iot_sensor_health` (avant 6.63.0 : 12 h ici contre 48 h dans l'outil, d'où deux dates
     * « figé depuis » différentes). Les champs jugés « figés » et leurs conditions (réserve :
     * seulement si la pompe de réserve a tourné) viennent de {@see SensorFieldMap}.
     */
    private const SENSOR_LOOKBACK_SECONDS = SensorHealthTool::DEFAULT_WINDOW_SECONDS;

    /** Seuil hors ligne du banc énergie (mesure toutes les 10 s) — miroir d'OverviewTool. */
    private const ENERGIE_OFFLINE_SECONDS = 90;

    /** Familles et environnements de production surveillés (le reste est du test). */
    private const PRODUCTION_ENVS = ['prod', 's3'];

    private string $projectRoot;

    public function __construct(
        private McpConfig $config,
        private PDO $pdo,
        private McpReadRepository $read,
        private McpHealthRepository $health,
        private McpOAuthRepository $oauth,
        private OfflineThresholdResolver $thresholds,
        private CronStatusReader $cron,
        private CertificateProbe $tls,
        ?string $projectRoot = null,
        ?\Closure $diskSpace = null,
        private ?ContactTimelineBuilder $timeline = null,
        ?string $cacheDir = null,
        ?DiskQuotaProbe $quota = null,
        ?LogLocator $logs = null,
    ) {
        // Défauts construits ici (pas d'autowiring des paramètres optionnels) : sonde de quota
        // système / DISK_QUOTA_BYTES, et journaux de l'APPLICATION (pas de la racine des galeries).
        $this->quota = $quota ?? new DiskQuotaProbe();
        $this->logs = $logs ?? new LogLocator(dirname(__DIR__, 3));
        $this->projectRoot = rtrim($projectRoot ?? dirname(__DIR__, 3), '/');
        $this->diskSpace = $diskSpace;
        // var/cache de l'APPLICATION (pas la racine des galeries $projectRoot, qui peut être
        // UPLOADS_BASE_DIR) : cache Twig compilé + états JSON de la supervision.
        $this->cacheDir = rtrim($cacheDir ?? dirname(__DIR__, 3) . '/var/cache', '/');
    }

    private string $cacheDir;

    private DiskQuotaProbe $quota;

    private LogLocator $logs;

    /** Journal listé dans le rapport au-delà de cette taille (information). */
    public const LARGE_LOG_BYTES = 50 * 1048576;

    /** @var (\Closure(string): array{free: float|false, total: float|false})|null Mesure disque (tests) */
    private ?\Closure $diskSpace;

    /**
     * @return array{status: string, summary: string, reasons: list<array{code: string, level: string, message: string}>, report: array<string, mixed>}
     */
    public function build(bool $checkTls = true): array
    {
        $now = time();
        $report = [
            'generated_at' => date('Y-m-d H:i:s', $now),
            'timezone' => date_default_timezone_get(),
            'application' => [
                'version' => Version::get(),
                'php_version' => PHP_VERSION,
                'default_env' => TableConfig::getDefaultEnvironment(),
            ],
            'database' => $this->database(),
            'tables' => $this->tables(),
            'cron' => $this->safe(fn (): array => $this->cron->status($now)),
            'devices' => $this->devices($now),
            'errors' => $this->errors($now),
            'galleries' => $this->galleries($now),
            'disk' => $this->disk(),
            'logs' => $this->logsBlock(),
            'cache' => $this->cacheBlock(),
            // null = non contrôlé (hôte inconnu ou contrôle non demandé) : n'influe pas sur le statut.
            'tls' => $checkTls ? $this->tlsBlock() : null,
            'mcp' => $this->mcp(),
        ];

        $evaluation = HealthRules::evaluate($report);
        $report['rules'] = [
            'note' => 'Statut = pire niveau déclenché ; règles et seuils : docs/MCP.md § Rapport de santé.',
        ];

        return [
            'status' => $evaluation['status'],
            'summary' => self::summary($evaluation['status'], $evaluation['reasons'], $report),
            'reasons' => $evaluation['reasons'],
            'report' => $report,
        ];
    }

    /**
     * @param list<array{code: string, level: string, message: string}> $reasons
     * @param array<string, mixed> $report
     */
    public static function summary(string $status, array $reasons, array $report): string
    {
        $online = 0;
        $active = 0;
        foreach ((array) ($report['devices'] ?? []) as $device) {
            if (is_array($device) && ($device['active'] ?? false) === true) {
                $active++;
                if (($device['online'] ?? false) === true) {
                    $online++;
                }
            }
        }
        $head = sprintf('[%s] %d/%d appareils actifs en ligne', strtoupper($status), $online, $active);
        if ($reasons === []) {
            return $head . ' ; CRON, BDD, disque et certificat OK.';
        }
        $messages = array_map(static fn (array $r): string => $r['message'], array_slice($reasons, 0, 3));
        $more = count($reasons) > 3 ? sprintf(' (+%d)', count($reasons) - 3) : '';

        return mb_substr($head . ' — ' . implode(' ', $messages) . $more, 0, 500);
    }

    /**
     * @return array<string, mixed>
     */
    private function database(): array
    {
        $started = microtime(true);
        try {
            $stmt = $this->pdo->query('SELECT VERSION()');
            $version = $stmt !== false ? (string) $stmt->fetchColumn() : null;
            if ((string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $version = 'sqlite';
            }

            return [
                'ok' => true,
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
                'server_version' => $version,
            ];
        } catch (\Throwable) {
            try {
                $this->pdo->query('SELECT 1');

                return ['ok' => true, 'latency_ms' => (int) round((microtime(true) - $started) * 1000), 'server_version' => null];
            } catch (\Throwable) {
                return ['ok' => false, 'error' => 'Connexion ou requête BDD en échec.'];
            }
        }
    }

    /**
     * Taille des tables de mesures principales (production) et des tables d'exploitation.
     *
     * @return array<string, mixed>
     */
    private function tables(): array
    {
        $names = [];
        foreach ($this->productionEnvs() as $env) {
            EnvironmentScope::run($env, static function () use (&$names): void {
                foreach (FieldCatalog::FAMILIES as $family) {
                    $names[] = FieldCatalog::dataTable($family);
                    $hb = FieldCatalog::heartbeatTable($family);
                    if ($hb !== null) {
                        $names[] = $hb;
                    }
                }
            });
        }
        $names = array_merge($names, ['error_alerts', 'notification_log', 'mcp_oauth_tokens', 'mcp_memory', 'mcp_health_reports']);
        $sizes = $this->safe(fn (): array => $this->health->tableSizes(array_values(array_unique($names))));
        $total = 0;
        foreach ($sizes as $size) {
            $total += is_array($size) ? (int) ($size['bytes'] ?? 0) : 0;
        }

        return [
            'sizes' => $sizes,
            'total_bytes' => $total,
            'note' => $sizes === [] ? 'Tailles indisponibles (information_schema illisible ou moteur non MySQL).' : 'Lignes : estimation InnoDB.',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function devices(int $now): array
    {
        $devices = [];
        // En s3, seules les tables ffp3 diffèrent (aquamobile ESP32-S3) ; les autres familles
        // y lisent les mêmes tables qu'en prod : on ne les compte qu'une fois.
        foreach ($this->productionEnvs() as $env) {
            $families = $env === 'prod' ? FieldCatalog::FAMILIES : ['ffp3'];
            foreach ($families as $family) {
                $devices[] = EnvironmentScope::run($env, fn (): array => $this->device($family, $env, $now));
            }
        }
        $devices[] = $this->pglDevice($now);

        return $devices;
    }

    /**
     * @return array<string, mixed>
     */
    private function device(string $family, string $env, int $now): array
    {
        $label = FieldCatalog::label($family) . ($env === 's3' ? ' — aquamobile ESP32-S3' : '');
        $out = ['id' => $family . ':' . $env, 'family' => $family, 'env' => $env, 'label' => $label];
        try {
            $latest = $this->read->latest($family);
        } catch (\Throwable $e) {
            return $out + ['online' => null, 'active' => false, 'status' => 'indisponible'] + self::unavailableNote($e);
        }
        if ($latest === null) {
            return $out + ['online' => null, 'active' => false, 'status' => 'jamais_vu'];
        }
        $lastReading = (string) $latest['reading_time'];
        $dataAge = self::age($lastReading, $now);

        $hbLast = null;
        $hbAge = null;
        try {
            $hb = $this->read->lastHeartbeat($family);
            if ($hb !== null && isset($hb['reading_time'])) {
                $hbLast = (string) $hb['reading_time'];
                $hbAge = self::age($hbLast, $now);
            }
        } catch (\Throwable) {
        }

        $threshold = $family === 'energie'
            ? self::ENERGIE_OFFLINE_SECONDS
            : $this->safeInt(fn (): int => $this->thresholds->resolveForFamily(strtoupper($family)), 3600);
        $silence = min($dataAge ?? PHP_INT_MAX, $hbAge ?? PHP_INT_MAX);
        $silence = $silence === PHP_INT_MAX ? null : $silence;
        $online = $silence !== null && $silence <= $threshold;
        $active = $silence !== null && $silence <= HealthRules::DEVICE_INACTIVE_SECONDS;

        $result = $out + [
            'online' => $online,
            'active' => $active,
            'status' => $online ? 'en_ligne' : ($active ? 'hors_ligne' : 'inactif'),
            'last_reading' => $lastReading,
            'last_heartbeat' => $hbLast,
            'silent_seconds' => $silence,
            'offline_threshold_seconds' => $threshold,
            'firmware_version' => isset($latest['version']) && $latest['version'] !== '' ? (string) $latest['version'] : null,
        ] + ($family === 'ffp3' && $online ? $this->sensorBlocks($family, $now) : []);
        // Module hors ligne mais encore actif (< 30 j) : résumé de la perte de contact (6.59.0).
        if (!$online && $active && $this->timeline !== null && $family !== 'energie') {
            $summary = OverviewTool::contactLossSummary($this->timeline, $family, $env);
            if ($summary !== null) {
                $result['contact_loss'] = $summary;
            }
        }

        return $result;
    }

    /**
     * Capteurs physiques muets ou figés sur la fenêtre de `iot_sensor_health` (FFP3 en ligne
     * uniquement : l'aquaponie est le seul module dont un capteur muet aveugle des automatismes —
     * marées, niveau bas, trop-plein ; incident du 30/10/2026). Même analyse que iot_sensor_health.
     *
     * `sensor_issues` (influe sur le statut) : capteur muet, ou figé parmi
     * {@see SensorFieldMap::frozenCheckedFields()}. `sensor_info` (n'influe PAS) : capteur désactivé
     * par le firmware (diagnostic d'écho `disabled`) — visible pour savoir qu'il est hors service.
     *
     * @return array{sensor_issues: list<array<string, mixed>>, sensor_info?: list<array<string, mixed>>}
     */
    private function sensorBlocks(string $family, int $now): array
    {
        try {
            $fields = FieldCatalog::sensorFields($family);
            $rows = $this->read->recentRows(
                $family,
                array_merge($fields, SensorFieldMap::auxiliaryColumns($family, $fields)),
                date('Y-m-d H:i:s', $now - self::SENSOR_LOOKBACK_SECONDS),
                date('Y-m-d H:i:s', $now)
            );
        } catch (\Throwable) {
            return ['sensor_issues' => []];
        }
        $frozenChecked = SensorFieldMap::frozenCheckedFields($family);
        $issues = [];
        $info = [];
        foreach ($fields as $field) {
            $analysis = SensorHealthTool::analyse($family, $field, $rows, SensorHealthTool::DEFAULT_FROZEN_MINUTES * 60);
            $status = (string) $analysis['status'];
            if ($status === SensorHealthTool::STATUS_DISABLED) {
                $info[] = [
                    'field' => $field,
                    'status' => $status,
                    'since' => $analysis['disabled_since'] ?? null,
                    'since_is_lower_bound' => (bool) ($analysis['disabled_since_is_lower_bound'] ?? false),
                    'last_value' => $analysis['last_value'] ?? null,
                ];
                continue;
            }
            if ($status === 'absent' || ($status === 'frozen' && in_array($field, $frozenChecked, true))) {
                $issues[] = [
                    'field' => $field,
                    'status' => $status,
                    'since' => $status === 'absent' ? ($analysis['null_since'] ?? null) : ($analysis['frozen_since'] ?? null),
                    'since_is_lower_bound' => (bool) ($analysis['since_is_lower_bound'] ?? false),
                    'last_value' => $analysis['last_value'] ?? null,
                ];
            }
        }

        return ['sensor_issues' => $issues] + ($info === [] ? [] : ['sensor_info' => $info]);
    }

    /**
     * @return array<string, mixed>
     */
    private function pglDevice(int $now): array
    {
        $out = ['id' => 'pgl', 'family' => 'pgl', 'env' => 'prod', 'label' => 'Poissonglouton (compteur de déchets plastiques)'];
        $stats = $this->safe(fn (): array => $this->read->pglStats(1, 1));
        if (($stats['available'] ?? false) !== true) {
            return $out + ['online' => null, 'active' => false, 'status' => 'indisponible'];
        }
        $latest = is_array($stats['latest_event'] ?? null) ? $stats['latest_event'] : [];
        $ages = array_filter([
            self::age(isset($latest['event_time']) ? (string) $latest['event_time'] : null, $now),
            self::age(is_string($stats['last_heartbeat'] ?? null) ? $stats['last_heartbeat'] : null, $now),
        ], static fn (?int $a): bool => $a !== null);
        $silence = $ages === [] ? null : min($ages);
        $online = $silence !== null && $silence <= PglConfig::ONLINE_THRESHOLD_SECONDS;
        $active = $silence !== null && $silence <= HealthRules::DEVICE_INACTIVE_SECONDS;

        return $out + [
            'online' => $silence === null ? null : $online,
            'active' => $active,
            'status' => $silence === null ? 'jamais_vu' : ($online ? 'en_ligne' : ($active ? 'hors_ligne' : 'inactif')),
            'last_heartbeat' => $stats['last_heartbeat'] ?? null,
            'silent_seconds' => $silence,
            'offline_threshold_seconds' => PglConfig::ONLINE_THRESHOLD_SECONDS,
            'firmware_version' => isset($latest['fw_version']) ? (string) $latest['fw_version'] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function errors(int $now): array
    {
        $day = date('Y-m-d H:i:s', $now - 86400);
        $week = date('Y-m-d H:i:s', $now - 7 * 86400);

        return [
            'server_errors_24h' => $this->safe(fn (): ?array => $this->health->errorCounts($day)),
            'server_errors_7d' => $this->safe(fn (): ?array => $this->health->errorCounts($week)),
            'notifications_24h' => $this->safe(fn (): ?array => $this->health->notificationCounts($day)),
            'notifications_7d' => $this->safe(fn (): ?array => $this->health->notificationCounts($week)),
            'note' => 'Détail : iot_alerts (erreurs regroupées, sujets des notifications sans destinataire).',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function galleries(int $now): array
    {
        $store = new GalleryNormalizedStore($this->projectRoot);
        $out = [];
        foreach (GalleryConfig::getAllowedSlugs() as $slug) {
            $dir = $store->galleryDir($slug);
            $latest = 0;
            $latestName = null;
            $count = 0;
            if (is_dir($dir)) {
                foreach (scandir($dir) ?: [] as $name) {
                    if (preg_match('/^[a-zA-Z0-9_.-]+\.jpe?g$/i', $name) !== 1 || !is_file($dir . '/' . $name)) {
                        continue;
                    }
                    $count++;
                    $ts = GalleryTimestamp::fromFile($dir . '/' . $name, $name)->getTimestamp();
                    if ($ts > $latest) {
                        $latest = $ts;
                        $latestName = $name;
                    }
                }
            }
            $age = $latest > 0 ? max(0, $now - $latest) : null;
            $active = $age !== null && $age <= HealthRules::GALLERY_INACTIVE_SECONDS;
            $out[] = [
                'slug' => $slug,
                'label' => GalleryConfig::getLabel($slug),
                'photos' => $count,
                'latest_photo' => $latestName,
                'latest_photo_at' => $latest > 0 ? date('Y-m-d H:i:s', $latest) : null,
                'latest_photo_age_seconds' => $age,
                'active' => $active,
                'stale' => $active && $age > HealthRules::GALLERY_STALE_SECONDS,
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function disk(): array
    {
        if ($this->diskSpace !== null) {
            ['free' => $free, 'total' => $total] = ($this->diskSpace)($this->projectRoot);
        } else {
            $free = @disk_free_space($this->projectRoot);
            $total = @disk_total_space($this->projectRoot);
        }
        $quota = $this->safe(fn (): array => $this->quota->probe());
        if ($quota !== [] && isset($quota['free_bytes']) && is_int($quota['free_bytes'])) {
            $quota['free_human'] = HealthRules::bytes($quota['free_bytes']);
        }
        if ($free === false) {
            return [
                'free_bytes' => null,
                'error' => 'disk_free_space indisponible (fonction désactivée par l\'hébergeur ?).',
                'quota' => $quota,
            ];
        }
        $pct = $total !== false && $total > 0 ? round($free / $total * 100, 1) : null;

        return [
            // Volume (partagé sur un mutualisé) : INFORMATION seulement depuis 6.63.0.
            'free_bytes' => (int) $free,
            'total_bytes' => $total !== false ? (int) $total : null,
            'free_percent' => $pct,
            'free_human' => HealthRules::bytes((int) $free),
            // Quota du compte : c'est lui que jugent les règles (inconnu = pas d'alerte).
            'quota' => $quota,
            'note' => 'free_* = volume (partagé sur un mutualisé, information) ; quota = place du compte, seule jugée par les règles.',
        ];
    }

    /**
     * Journaux de plus de {@see self::LARGE_LOG_BYTES} (information, sans lecture du contenu) :
     * chemin relatif à la racine de l'application, taille, type. Le fichier nu `cronlog.txt` et
     * `error_log` sont vidés chaque nuit par {@see \App\Command\LogRotationCommand}.
     *
     * @return array<string, mixed>
     */
    private function logsBlock(): array
    {
        $root = $this->logs->projectRoot();
        $files = $this->safe(fn (): array => $this->logs->files());
        $large = [];
        $total = 0;
        foreach ($files as $file) {
            $total += (int) $file['size'];
            if ((int) $file['size'] <= self::LARGE_LOG_BYTES) {
                continue;
            }
            $path = (string) $file['path'];
            $large[] = [
                'path' => str_starts_with($path, $root . '/') ? substr($path, strlen($root) + 1) : basename($path),
                'kind' => (string) $file['kind'],
                'size_bytes' => (int) $file['size'],
                'size_human' => HealthRules::bytes((int) $file['size']),
                'modified_at' => date('Y-m-d H:i:s', (int) $file['mtime']),
            ];
        }
        usort($large, static fn (array $a, array $b): int => $b['size_bytes'] <=> $a['size_bytes']);

        return [
            'files' => count($files),
            'total_bytes' => $total,
            'large_threshold_bytes' => self::LARGE_LOG_BYTES,
            'large' => $large,
            'note' => 'Information (n\'influe pas sur le statut). cronlog.txt nu et error_log : vidés chaque nuit, archives '
                . '<nom>.AAAA-MM-JJ.gz gardées LOG_RETENTION_DAYS jours (défaut 30).',
        ];
    }

    /**
     * `var/cache` inscriptible ? (contrôle SANS écriture : is_writable seulement.) Un cache non
     * inscriptible fait rendre les pages sans cache (plus lent, WARNING au journal) et empêche la
     * supervision d'enregistrer ses états (alertes dérivées, incidents de disponibilité).
     * Le point de vue est celui du processus qui construit le rapport : PHP web pour
     * iot_health_report / GET /health/report.json, compte du CRON pour le rapport planifié.
     *
     * @return array<string, mixed>
     */
    private function cacheBlock(): array
    {
        $root = $this->cacheDir;
        $twig = isset($_ENV['TWIG_CACHE_DIR']) && is_string($_ENV['TWIG_CACHE_DIR']) && $_ENV['TWIG_CACHE_DIR'] !== ''
            ? rtrim($_ENV['TWIG_CACHE_DIR'], '/')
            : $root . '/twig';
        $rootExists = is_dir($root);
        // Dossier absent : il sera créé s'il est créable (parent inscriptible).
        $rootWritable = $rootExists ? is_writable($root) : is_writable(dirname($root));
        $twigExists = is_dir($twig);
        $twigWritable = $twigExists ? is_writable($twig) : $rootWritable;

        $badSubdirs = [];
        if ($twigExists) {
            $scanned = 0;
            foreach (scandir($twig) ?: [] as $name) {
                if ($name === '.' || $name === '..' || !is_dir($twig . '/' . $name)) {
                    continue;
                }
                if (++$scanned > 512) {
                    break;
                }
                if (!is_writable($twig . '/' . $name)) {
                    $badSubdirs[] = $name;
                }
            }
        }
        $badStateFiles = [];
        foreach (glob($root . '/*.json') ?: [] as $file) {
            if (!is_writable($file)) {
                $badStateFiles[] = basename($file);
            }
        }

        return [
            'dir' => 'var/cache',
            'exists' => $rootExists,
            'writable' => $rootWritable,
            'twig_exists' => $twigExists,
            'twig_writable' => $twigWritable,
            'twig_unwritable_subdirs' => count($badSubdirs),
            'twig_unwritable_sample' => array_slice($badSubdirs, 0, 5),
            'unwritable_state_files' => array_slice($badStateFiles, 0, 10),
            'note' => 'Vu par le processus qui génère ce rapport (web pour iot_health_report, compte du CRON pour le rapport planifié). '
                . 'Correctif : propriétaire / droits de var/cache (docs/deployment/CACHE_MANAGEMENT.md).',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function tlsBlock(): ?array
    {
        $host = $this->config->healthTlsHost();
        if ($host === null) {
            return null;
        }

        return $this->tls->probe($host);
    }

    /**
     * @return array<string, mixed>
     */
    private function mcp(): array
    {
        $active = $this->safe(fn (): array => $this->oauth->activeConnections());

        return [
            'active_connections' => $active['connections'] ?? null,
            'active_users' => $active['users'] ?? null,
            'control_enabled' => $this->config->isControlEnabled(),
            'allowed_envs' => $this->config->allowedEnvs(),
            'health_report_enabled' => $this->config->isHealthReportEnabled(),
        ];
    }

    /**
     * @return list<string>
     */
    private function productionEnvs(): array
    {
        // prod est toujours inclus (installations réelles) ; s3 si MCP_ALLOWED_ENVS l'autorise.
        return array_values(array_unique(array_merge(['prod'], array_intersect(self::PRODUCTION_ENVS, $this->config->allowedEnvs()))));
    }

    /**
     * Note d'un module dont la table de mesures est illisible : table absente (module pas encore
     * installé, ex. banc énergie) distinguée d'une autre erreur. N'influe pas sur le statut.
     *
     * @return array{table_missing: bool, note: string}
     */
    public static function unavailableNote(\Throwable $e): array
    {
        if (\App\Util\MissingTable::is($e)) {
            return [
                'table_missing' => true,
                'note' => 'Table de mesures absente : module pas encore installé ou migration non appliquée (iot_db_schema, « migrations »: true).',
            ];
        }

        return ['table_missing' => false, 'note' => 'Table de mesures illisible (erreur SQL autre que « table absente »).'];
    }

    private static function age(?string $sqlDate, int $now): ?int
    {
        if ($sqlDate === null || $sqlDate === '') {
            return null;
        }
        $ts = strtotime($sqlDate);

        return $ts === false ? null : max(0, $now - $ts);
    }

    /**
     * @template T of array<mixed>|null
     * @param callable(): T $callback
     * @return T|array{}
     */
    private function safe(callable $callback): ?array
    {
        try {
            return $callback();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param callable(): int $callback
     */
    private function safeInt(callable $callback, int $default): int
    {
        try {
            return $callback();
        } catch (\Throwable) {
            return $default;
        }
    }
}

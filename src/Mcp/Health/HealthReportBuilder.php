<?php

declare(strict_types=1);

namespace App\Mcp\Health;

use App\Config\GalleryConfig;
use App\Config\PglConfig;
use App\Config\TableConfig;
use App\Config\Version;
use App\Mcp\McpConfig;
use App\Mcp\Support\EnvironmentScope;
use App\Mcp\Support\FieldCatalog;
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
 * par galerie, espace disque, expiration du certificat TLS, connexions MCP actives ; puis un
 * statut global calculé par {@see HealthRules} et un résumé d'une ligne.
 *
 * Lecture seule : aucune écriture (le stockage est fait par la commande CRON). Chaque bloc est
 * isolé : une source indisponible (table absente, disque illisible, TLS injoignable) est
 * signalée dans son bloc sans faire échouer le rapport. Aucun secret ni donnée personnelle.
 */
final class HealthReportBuilder
{
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
    ) {
        $this->projectRoot = rtrim($projectRoot ?? dirname(__DIR__, 3), '/');
        $this->diskSpace = $diskSpace;
    }

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
        } catch (\Throwable) {
            return $out + ['online' => null, 'active' => false, 'status' => 'indisponible', 'note' => 'Table de mesures absente ou illisible.'];
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

        return $out + [
            'online' => $online,
            'active' => $active,
            'status' => $online ? 'en_ligne' : ($active ? 'hors_ligne' : 'inactif'),
            'last_reading' => $lastReading,
            'last_heartbeat' => $hbLast,
            'silent_seconds' => $silence,
            'offline_threshold_seconds' => $threshold,
            'firmware_version' => isset($latest['version']) && $latest['version'] !== '' ? (string) $latest['version'] : null,
        ];
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
        if ($free === false) {
            return ['free_bytes' => null, 'error' => 'disk_free_space indisponible (fonction désactivée par l\'hébergeur ?).'];
        }
        $pct = $total !== false && $total > 0 ? round($free / $total * 100, 1) : null;

        return [
            'free_bytes' => (int) $free,
            'total_bytes' => $total !== false ? (int) $total : null,
            'free_percent' => $pct,
            'free_human' => HealthRules::bytes((int) $free),
            'note' => 'Sur un mutualisé, le total est celui du volume ; le quota du compte se lit dans cPanel.',
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

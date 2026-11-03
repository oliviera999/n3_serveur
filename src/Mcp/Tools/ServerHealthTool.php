<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Config\GalleryConfig;
use App\Config\Paths;
use App\Config\Version;
use App\Mcp\McpConfig;
use App\Mcp\McpContext;
use App\Mcp\ToolResult;
use App\Service\GalleryNormalizedStore;
use PDO;

/**
 * `iot_server_health` — santé du serveur lui-même (version, BDD, CRON, galeries).
 */
final class ServerHealthTool extends AbstractTool
{
    private string $projectRoot;

    public function __construct(McpConfig $config, private PDO $pdo, ?string $projectRoot = null)
    {
        parent::__construct($config);
        $this->projectRoot = $projectRoot ?? Paths::getProjectRoot();
    }

    public function name(): string
    {
        return 'iot_server_health';
    }

    public function title(): string
    {
        return 'Santé du serveur IoT';
    }

    public function description(): string
    {
        return 'État du serveur iot.olution.info : version applicative, connexion BDD (latence, version MariaDB), '
            . 'dernier passage du CRON horaire (tâches de supervision et alertes), présence de redémarrage pompe programmé, '
            . 'et pour chaque galerie photo : nombre de photos, date de la plus récente, photos encore à normaliser. '
            . 'Utile pour savoir si les alertes et les mesures sont fiables avant de conclure à une panne d\'appareil.';
    }

    public function inputSchema(): array
    {
        return $this->objectSchema([]);
    }

    public function call(array $arguments, McpContext $context): ToolResult
    {
        $db = ['ok' => false];
        $started = microtime(true);
        try {
            $version = $this->pdo->query('SELECT VERSION()');
            $db = [
                'ok' => true,
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
                'server_version' => $version !== false ? (string) $version->fetchColumn() : null,
                'driver' => (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME),
            ];
        } catch (\Throwable) {
            $db = ['ok' => false, 'error' => 'Connexion ou requête BDD en échec.'];
        }

        $hourly = $this->projectRoot . '/var/cache/cron_last_hourly.timestamp';
        $lastHourly = is_file($hourly) ? (int) @file_get_contents($hourly) : 0;
        $lastNormalize = $this->projectRoot . '/var/cache/cron_last_gallery_normalize.timestamp';
        $normalizeTs = is_file($lastNormalize) ? (int) @file_get_contents($lastNormalize) : 0;

        return ToolResult::json([
            'version' => Version::get(),
            'server_time' => date('Y-m-d H:i:s'),
            'timezone' => date_default_timezone_get(),
            'default_env' => \App\Config\TableConfig::getDefaultEnvironment(),
            'php_version' => PHP_VERSION,
            'database' => $db,
            'cron' => [
                'last_hourly_run' => $lastHourly > 0 ? date('Y-m-d H:i:s', $lastHourly) : null,
                'last_hourly_age_seconds' => $lastHourly > 0 ? max(0, time() - $lastHourly) : null,
                'last_gallery_normalize' => $normalizeTs > 0 ? date('Y-m-d H:i:s', $normalizeTs) : null,
                'note' => 'Le CRON tourne chaque minute ; les tâches horaires (hors-ligne, digest) marquent cron_last_hourly. '
                    . 'Un âge > 2 h indique un CRON arrêté.',
                'pump_restart_scheduled' => is_file(sys_get_temp_dir() . '/pump_restart_scheduled.flag'),
            ],
            'galleries' => $this->galleries(),
            'mcp' => [
                'control_enabled' => $this->config->isControlEnabled(),
                'allowed_envs' => $this->config->allowedEnvs(),
            ],
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function galleries(): array
    {
        $store = new GalleryNormalizedStore($this->projectRoot);
        $out = [];
        foreach (GalleryConfig::getAllowedSlugs() as $slug) {
            $dir = $store->galleryDir($slug);
            $count = 0;
            $latest = 0;
            $pending = 0;
            if (is_dir($dir)) {
                foreach (scandir($dir) ?: [] as $name) {
                    if (!preg_match('/\.(jpe?g)$/i', $name) || !is_file($dir . '/' . $name)) {
                        continue;
                    }
                    $count++;
                    $latest = max($latest, (int) @filemtime($dir . '/' . $name));
                    if (!$store->isPurged($slug, $name) && !$store->hasCurrentMeta($slug, $name)) {
                        $pending++;
                    }
                }
            }
            $out[] = [
                'slug' => $slug,
                'label' => GalleryConfig::getLabel($slug),
                'directory_exists' => is_dir($dir),
                'photos' => $count,
                'latest_photo_at' => $latest > 0 ? date('Y-m-d H:i:s', $latest) : null,
                'latest_photo_age_seconds' => $latest > 0 ? max(0, time() - $latest) : null,
                'pending_normalization' => $pending,
            ];
        }

        return $out;
    }
}

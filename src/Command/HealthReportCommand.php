<?php

declare(strict_types=1);

namespace App\Command;

use App\Mcp\Health\HealthReportBuilder;
use App\Mcp\Health\HealthRules;
use App\Mcp\McpConfig;
use App\Notification\NotificationCategory;
use App\Notification\Severity;
use App\Repository\McpHealthRepository;
use App\Service\LogService;
use App\Service\NotificationService;

/**
 * Sous-commande CRON : rapport de santé régulier du site (connecteur MCP).
 *
 * Exécutée par {@see CronOrchestrator} à chaque passage, SOUS SON VERROU flock ; elle tient
 * elle-même son échéance (`var/cache/cron_last_health_report.timestamp`) :
 *  - active seulement si MCP_HEALTH_REPORT_ENABLED=true ;
 *  - due quand MCP_HEALTH_REPORT_INTERVAL_SECONDS (défaut 86 400 s) est écoulé et, pour un
 *    intervalle d'au moins 24 h, à partir de l'heure locale MCP_HEALTH_REPORT_HOUR (défaut 7 h) ;
 *  - construit le rapport ({@see HealthReportBuilder}), le stocke dans `mcp_health_reports`,
 *    purge les rapports de plus de MCP_HEALTH_REPORT_RETENTION_DAYS jours ;
 *  - envoie un e-mail via {@see NotificationService} (jamais `mail()`) :
 *      · `alert` (défaut) : statut orange (P2) ou rouge (P1), `sendImmediateAlert()` — politique
 *        de notification appliquée ;
 *      · `daily` : chaque rapport, vert compris, `sendScheduledReport()` — le plancher de
 *        sévérité du mode est franchi (demande explicite), le mode `none` reste respecté ;
 *      · `never` : aucun e-mail.
 *    Envoi immédiat (pas de digest), anti-spam par clé `mcp:health-report:<jour>:<statut>`.
 *
 * Tolérante : table absente (migration non jouée) → rapport non stocké mais e-mail possible,
 * journalisé, sans interrompre le CRON. L'horodatage est posé même en cas d'échec, pour ne
 * pas reconstruire le rapport à chaque minute.
 */
class HealthReportCommand
{
    public const STATE_FILENAME = 'cron_last_health_report.timestamp';

    private string $stateDir;

    public function __construct(
        private McpConfig $config,
        private HealthReportBuilder $builder,
        private McpHealthRepository $repo,
        private NotificationService $notifier,
        private LogService $logger,
        ?string $stateDir = null,
    ) {
        $this->stateDir = $stateDir ?? dirname(__DIR__, 2) . '/var/cache';
    }

    public function isDue(?int $now = null): bool
    {
        if (!$this->config->isHealthReportEnabled()) {
            return false;
        }
        $now ??= time();
        $last = $this->lastRun();
        $interval = $this->config->healthReportIntervalSeconds();
        $hour = $interval >= 86400 ? $this->config->healthReportHour() : null;

        if ($hour !== null) {
            // Quotidien à heure fixe : une fois par jour calendaire, à partir de l'heure demandée.
            $todaySlot = strtotime(date('Y-m-d', $now) . sprintf(' %02d:00:00', $hour));
            if ($todaySlot === false || $now < $todaySlot) {
                return false;
            }

            return $last === null || $last < $todaySlot && ($now - $last) >= (int) ($interval * 0.5);
        }

        return $last === null || ($now - $last) >= $interval;
    }

    /**
     * Exécute le rapport s'il est dû (ou toujours si `$force`).
     *
     * @return array<string, mixed>|null Résultat (statut, id stocké, e-mail), null si non dû
     */
    public function execute(bool $force = false): ?array
    {
        if (!$force && !$this->isDue()) {
            return null;
        }
        $this->markRun();

        $result = $this->builder->build();
        $status = $result['status'];
        $generatedAt = (string) ($result['report']['generated_at'] ?? date('Y-m-d H:i:s'));

        $id = null;
        try {
            $id = $this->repo->store($generatedAt, $status, $result['summary'], $result['reasons'], $result['report'], $force ? 'manuel' : 'cron', false);
            $purged = $this->repo->purgeOlderThan(date('Y-m-d H:i:s', time() - $this->config->healthReportRetentionDays() * 86400));
            if ($purged > 0) {
                $this->logger->info('Rapport de santé : ' . $purged . ' rapport(s) ancien(s) purgé(s).');
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Rapport de santé non stocké (table mcp_health_reports absente ?) : ' . $e->getMessage());
        }

        $emailed = false;
        if ($this->shouldEmail($status)) {
            $severity = match ($status) {
                HealthRules::STATUS_RED => Severity::Critical,
                HealthRules::STATUS_ORANGE => Severity::Alert,
                default => Severity::Info,
            };
            $day = date('Y-m-d', strtotime($generatedAt) ?: time());
            $subject = sprintf('Rapport de santé %s — %s', strtoupper($status), date('d/m/Y', strtotime($generatedAt) ?: time()));
            $key = 'mcp:health-report:' . $day . ':' . $status;
            $emailed = $this->config->healthReportEmail() === McpConfig::HEALTH_EMAIL_DAILY
                // Envoi quotidien demandé : part même si le statut est vert (P3) en mode « important ».
                ? $this->notifier->sendScheduledReport($severity, $subject, self::emailBody($result), $key)
                // Défaut : orange (P2) / rouge (P1), politique de notification appliquée.
                : $this->notifier->sendImmediateAlert($severity, NotificationCategory::System, 'SERVEUR', $subject, self::emailBody($result), $key);
            if ($emailed && $id !== null) {
                try {
                    $this->repo->markEmailed($id);
                } catch (\Throwable) {
                }
            }
        }

        $this->logger->info(sprintf('[mcp-health] statut=%s raisons=%d stocke=%s email=%s', $status, count($result['reasons']), $id !== null ? '#' . $id : 'non', $emailed ? 'oui' : 'non'));

        return ['status' => $status, 'summary' => $result['summary'], 'id' => $id, 'emailed' => $emailed];
    }

    public function shouldEmail(string $status): bool
    {
        return match ($this->config->healthReportEmail()) {
            McpConfig::HEALTH_EMAIL_NEVER => false,
            McpConfig::HEALTH_EMAIL_DAILY => true,
            default => $status !== HealthRules::STATUS_GREEN,
        };
    }

    /**
     * @param array{status: string, summary: string, reasons: list<array{code: string, level: string, message: string}>, report: array<string, mixed>} $result
     */
    public static function emailBody(array $result): string
    {
        $lines = [$result['summary'], ''];
        if ($result['reasons'] === []) {
            $lines[] = 'Aucune règle déclenchée.';
        } else {
            $lines[] = 'Points relevés :';
            foreach ($result['reasons'] as $reason) {
                $lines[] = sprintf('- [%s] %s', strtoupper($reason['level']), $reason['message']);
            }
        }
        $lines[] = '';
        $lines[] = 'Appareils :';
        foreach ((array) ($result['report']['devices'] ?? []) as $device) {
            if (!is_array($device)) {
                continue;
            }
            $lines[] = sprintf(
                '- %s : %s%s',
                (string) ($device['label'] ?? $device['id'] ?? '?'),
                (string) ($device['status'] ?? '?'),
                is_string($device['firmware_version'] ?? null) ? ' (firmware ' . $device['firmware_version'] . ')' : ''
            );
        }
        $lines[] = '';
        $lines[] = 'Détail et historique : outils iot_health_report et iot_health_history du connecteur Claude.';

        return implode("\n", $lines);
    }

    private function lastRun(): ?int
    {
        $path = $this->stateDir . '/' . self::STATE_FILENAME;
        if (!is_file($path)) {
            return null;
        }
        $value = (int) trim((string) @file_get_contents($path));

        return $value > 0 ? $value : null;
    }

    private function markRun(): void
    {
        if (!is_dir($this->stateDir)) {
            @mkdir($this->stateDir, 0755, true);
        }
        @file_put_contents($this->stateDir . '/' . self::STATE_FILENAME, (string) time());
    }
}

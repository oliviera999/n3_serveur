<?php

declare(strict_types=1);

namespace App\Mcp\Health;

use App\Command\CronOrchestrator;
use App\Command\HealthReportCommand;

/**
 * État du CRON reconstitué, en LECTURE SEULE, à partir de ce que le code trace déjà :
 *
 *  - `var/cache/cron_last_run.json` : début, fin, durée et statut du dernier passage de
 *    l'orchestrateur (écrit par CronOrchestrator à chaque run, depuis la 6.52.0) ;
 *  - verrou `cron_orchestrator.lock` (dossier temporaire) : run en cours si le verrou est
 *    tenu (test `flock(LOCK_SH | LOCK_NB)` aussitôt relâché, sans rien écrire) ; PID du dernier run ;
 *  - horodatages des sous-commandes : `cron_last_hourly.timestamp` (supervision hors ligne,
 *    digest, purge MCP), `cron_last_gallery_normalize.timestamp`, `cron_last_health_report.timestamp` ;
 *  - flag `pump_restart_scheduled.flag` (redémarrage pompe aquarium programmé, 5 min) ;
 *  - repli : dernière ligne « --- Fin orchestrateur CRON --- » du journal applicatif, si
 *    `cron_last_run.json` est absent (premier déploiement).
 *
 * Cadences attendues : orchestrateur chaque minute (crontab), horaires toutes les
 * `CRON_HOURLY_INTERVAL_SECONDS` (défaut 3600 s), normalisation galerie toutes les
 * `GALLERY_NORMALIZE_INTERVAL_SECONDS` (défaut 600 s), rapport de santé selon
 * `MCP_HEALTH_REPORT_INTERVAL_SECONDS` (défaut 86 400 s). Retard = âge − intervalle.
 */
final class CronStatusReader
{
    /** Au-delà, l'orchestrateur (crontab chaque minute) est considéré en retard. */
    public const ORCHESTRATOR_LATE_SECONDS = 300;

    private string $stateDir;
    private string $lockDir;

    public function __construct(
        string $projectRoot,
        private LogLocator $logs,
        private int $hourlyIntervalSeconds = 3600,
        private int $galleryIntervalSeconds = 600,
        private ?int $healthIntervalSeconds = null,
        ?string $stateDir = null,
        ?string $lockDir = null,
    ) {
        $this->stateDir = $stateDir ?? rtrim($projectRoot, '/') . '/var/cache';
        $this->lockDir = $lockDir ?? sys_get_temp_dir();
    }

    /**
     * @return array<string, mixed>
     */
    public function status(?int $now = null): array
    {
        $now ??= time();
        $lastRun = $this->lastRun();
        $lock = $this->lockState();

        $orchestratorTs = $lastRun['started_at'] ?? null;
        $source = $lastRun !== null ? 'cron_last_run.json' : null;
        if ($orchestratorTs === null) {
            $fromLog = $this->lastRunFromLog();
            if ($fromLog !== null) {
                $orchestratorTs = $fromLog;
                $source = 'journal (marqueur « Fin orchestrateur CRON »)';
            }
        }
        $orchestratorAge = $orchestratorTs !== null ? max(0, $now - $orchestratorTs) : null;

        $tasks = [
            $this->task('orchestrateur', 'CronOrchestrator (crontab chaque minute)', $orchestratorTs, 60, $now, self::ORCHESTRATOR_LATE_SECONDS),
            $this->task(
                'taches_horaires',
                'Tâches horaires : appareils silencieux, hors ligne, digest des notifications, purge MCP',
                $this->timestamp(CronOrchestrator::HOURLY_STATE_FILENAME),
                $this->hourlyIntervalSeconds,
                $now
            ),
            $this->task(
                'normalisation_galerie',
                'Normalisation des photos de galerie (timelapse)',
                $this->timestamp(CronOrchestrator::GALLERY_NORMALIZE_STATE_FILENAME),
                $this->galleryIntervalSeconds,
                $now
            ),
        ];
        if ($this->healthIntervalSeconds !== null) {
            $tasks[] = $this->task(
                'rapport_sante',
                'Rapport de santé régulier (HealthReportCommand)',
                $this->timestamp(HealthReportCommand::STATE_FILENAME),
                $this->healthIntervalSeconds,
                $now
            );
        }

        $flag = $this->lockDir . '/' . CronOrchestrator::PUMP_RESTART_FLAG_FILENAME;
        $flagTs = is_file($flag) ? (int) @file_get_contents($flag) : null;

        return [
            'now' => date('Y-m-d H:i:s', $now),
            'orchestrator' => [
                'last_start' => $orchestratorTs !== null ? date('Y-m-d H:i:s', $orchestratorTs) : null,
                'last_end' => isset($lastRun['finished_at']) ? date('Y-m-d H:i:s', (int) $lastRun['finished_at']) : null,
                'age_seconds' => $orchestratorAge,
                'duration_ms' => $lastRun['duration_ms'] ?? null,
                'last_status' => $lastRun['status'] ?? null,
                'source' => $source,
                'running_now' => $lock['held'],
                'lock_file' => $lock['exists'] ? CronOrchestrator::LOCK_FILENAME : null,
                'late' => $orchestratorAge === null || $orchestratorAge > self::ORCHESTRATOR_LATE_SECONDS,
            ],
            'tasks' => $tasks,
            'pump_restart_scheduled' => $flagTs !== null && $flagTs > 0
                ? ['since' => date('Y-m-d H:i:s', $flagTs), 'restart_due_at' => date('Y-m-d H:i:s', $flagTs + 300)]
                : null,
            'note' => 'Retard = âge du dernier passage − intervalle attendu. « en_cours » dans last_status avec running_now=false '
                . 'signale un run interrompu (erreur fatale, délai dépassé) : voir le journal d\'erreurs PHP (lignes [run-cron]).',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function task(string $id, string $label, ?int $lastTs, int $interval, int $now, ?int $lateAfter = null): array
    {
        $age = $lastTs !== null ? max(0, $now - $lastTs) : null;
        $delay = $age !== null ? max(0, $age - $interval) : null;
        $tolerance = $lateAfter ?? (int) max(300, $interval * 0.5);

        return [
            'id' => $id,
            'label' => $label,
            'last_run' => $lastTs !== null ? date('Y-m-d H:i:s', $lastTs) : null,
            'age_seconds' => $age,
            'expected_interval_seconds' => $interval,
            'delay_seconds' => $delay,
            'late' => $age === null ? null : ($lateAfter !== null ? $age > $lateAfter : $delay > $tolerance),
        ];
    }

    /**
     * @return array{started_at?: int, finished_at?: int|null, duration_ms?: int|null, status?: string}|null
     */
    private function lastRun(): ?array
    {
        $path = $this->stateDir . '/' . CronOrchestrator::LAST_RUN_STATE_FILENAME;
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($path), true);
        if (!is_array($data) || !isset($data['started_at']) || !is_numeric($data['started_at'])) {
            return null;
        }

        return [
            'started_at' => (int) $data['started_at'],
            'finished_at' => isset($data['finished_at']) && is_numeric($data['finished_at']) ? (int) $data['finished_at'] : null,
            'duration_ms' => isset($data['duration_ms']) && is_numeric($data['duration_ms']) ? (int) $data['duration_ms'] : null,
            'status' => is_string($data['status'] ?? null) ? $data['status'] : 'inconnu',
        ];
    }

    private function timestamp(string $file): ?int
    {
        $path = $this->stateDir . '/' . $file;
        if (!is_file($path)) {
            return null;
        }
        $value = (int) trim((string) @file_get_contents($path));

        return $value > 0 ? $value : null;
    }

    /**
     * @return array{exists: bool, held: bool}
     */
    private function lockState(): array
    {
        $path = $this->lockDir . '/' . CronOrchestrator::LOCK_FILENAME;
        if (!is_file($path)) {
            return ['exists' => false, 'held' => false];
        }
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            return ['exists' => true, 'held' => false];
        }
        $held = !flock($handle, LOCK_SH | LOCK_NB);
        if (!$held) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);

        return ['exists' => true, 'held' => $held];
    }

    /**
     * Horodatage de la dernière ligne « Fin orchestrateur CRON » du journal applicatif le plus récent
     * (lecture des 256 derniers Kio seulement).
     */
    private function lastRunFromLog(): ?int
    {
        foreach (array_slice($this->logs->files('applicatif'), 0, 2) as $file) {
            $handle = @fopen($file['path'], 'r');
            if ($handle === false) {
                continue;
            }
            $size = $file['size'];
            fseek($handle, max(0, $size - 262144));
            $chunk = (string) stream_get_contents($handle);
            fclose($handle);
            if (preg_match_all('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\][^\n]*(?:Fin|Début) orchestrateur CRON/m', $chunk, $m) > 0) {
                $ts = strtotime((string) end($m[1]));
                if ($ts !== false) {
                    return $ts;
                }
            }
        }

        return null;
    }
}

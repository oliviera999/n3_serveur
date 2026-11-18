<?php

declare(strict_types=1);

namespace App\Command;

use App\Mcp\Health\LogLocator;
use App\Service\LogService;
use App\Service\OperationalSettingsService;

/**
 * Rotation quotidienne des journaux que Monolog NE gère PAS (6.63.0).
 *
 * Constat du 18/11/2026 : `logs/cronlog.txt` pesait 118 Mo alors que les copies datées
 * `cronlog-AAAA-MM-JJ.txt` existaient. Ces copies sont écrites par Monolog
 * ({@see \App\Service\LogService}, RotatingFileHandler) qui n'écrit JAMAIS le fichier nu
 * `LOG_FILE_PATH` : celui-ci reçoit la sortie d'un processus externe (redirection `>>` d'une
 * ligne de crontab — en production la sortie du `git pull` + hook post-merge chaque minute).
 * Même chose pour le journal d'erreurs PHP (`error_log`), écrit par PHP et l'hébergeur.
 *
 * Une fois par jour (premier passage du CRON après minuit), pour chaque fichier ciblé non vide :
 *  1. copie compressée `<nom>.<AAAA-MM-JJ de la veille>.gz` (copie brute si zlib absent) ;
 *  2. troncature du fichier courant (même inode, droits conservés : le processus qui écrit
 *     en ajout continue d'écrire sans réouverture) ;
 *  3. suppression des copies de plus de `LOG_RETENTION_DAYS` jours (défaut 30, 0 = jamais).
 *
 * Fichiers ciblés : le fichier nu `LOG_FILE_PATH` et les journaux d'erreurs PHP trouvés par
 * {@see LogLocator}. Jamais les rotations Monolog (`cronlog-AAAA-MM-JJ.txt`, `hmac-audit-*.log`),
 * dont la rétention reste `LOG_ROTATE_DAYS`. Best effort : une erreur est journalisée, jamais levée.
 */
class LogRotationCommand
{
    public const STATE_FILENAME = 'log_rotation_last.date';
    public const DEFAULT_RETENTION_DAYS = 30;
    private const CHUNK = 1048576;

    private string $stateDir;

    public function __construct(
        private LogLocator $locator,
        private ?LogService $logger = null,
        private ?OperationalSettingsService $operationalSettings = null,
        ?string $stateDir = null,
    ) {
        $this->stateDir = rtrim($stateDir ?? dirname(__DIR__, 2) . '/var/cache', '/');
    }

    /**
     * @return array{rotated: list<array{path: string, archive: string, bytes: int}>, deleted: list<string>, skipped: bool}
     */
    public function execute(?int $now = null): array
    {
        $now ??= time();
        $today = date('Y-m-d', $now);
        $statePath = $this->stateDir . '/' . self::STATE_FILENAME;
        $last = is_file($statePath) ? trim((string) @file_get_contents($statePath)) : '';
        if ($last === $today) {
            return ['rotated' => [], 'deleted' => [], 'skipped' => true];
        }

        $label = date('Y-m-d', $now - 86400);
        $rotated = [];
        $deleted = [];
        foreach ($this->targets() as $path) {
            try {
                $result = $this->rotate($path, $label);
                if ($result !== null) {
                    $rotated[] = $result;
                }
                $deleted = array_merge($deleted, $this->purge($path, $now));
            } catch (\Throwable $e) {
                $this->logger?->warning('Rotation du journal ' . basename($path) . ' en erreur : ' . $e->getMessage());
            }
        }

        if (is_dir($this->stateDir) || @mkdir($this->stateDir, 0775, true)) {
            @file_put_contents($statePath, $today);
        }
        if ($rotated !== [] || $deleted !== []) {
            $this->logger?->info(sprintf(
                'Rotation des journaux : %d fichier(s) archivé(s) et vidé(s), %d copie(s) ancienne(s) supprimée(s).',
                count($rotated),
                count($deleted)
            ));
        }

        return ['rotated' => $rotated, 'deleted' => $deleted, 'skipped' => false];
    }

    public function retentionDays(): int
    {
        $days = $this->operationalSettings?->int('LOG_RETENTION_DAYS', self::DEFAULT_RETENTION_DAYS)
            ?? (int) ($_ENV['LOG_RETENTION_DAYS'] ?? self::DEFAULT_RETENTION_DAYS);

        return max(0, $days);
    }

    /**
     * Fichiers à faire tourner (chemins absolus, sans doublon).
     *
     * @return list<string>
     */
    public function targets(): array
    {
        $current = basename(trim((string) ($_ENV['LOG_FILE_PATH'] ?? 'cronlog.txt')) ?: 'cronlog.txt');
        $out = [];
        foreach ($this->locator->files() as $file) {
            $name = basename($file['path']);
            if (($file['kind'] === 'applicatif' && $name === $current) || $file['kind'] === 'erreurs_php') {
                $out[$file['path']] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * @return array{path: string, archive: string, bytes: int}|null
     */
    private function rotate(string $path, string $label): ?array
    {
        clearstatcache(true, $path);
        $size = @filesize($path);
        if ($size === false || $size === 0 || !is_writable($path)) {
            return null;
        }
        $gzip = function_exists('gzopen');
        $base = $path . '.' . $label;
        $archive = $base . ($gzip ? '.gz' : '');
        if (file_exists($archive)) {
            $archive = $base . '-' . date('His') . ($gzip ? '.gz' : '');
        }

        $in = @fopen($path, 'rb');
        if ($in === false) {
            return null;
        }
        $out = $gzip ? @gzopen($archive, 'wb6') : @fopen($archive, 'wb');
        if ($out === false) {
            fclose($in);
            throw new \RuntimeException('copie impossible (' . basename($archive) . ')');
        }
        $copied = 0;
        while (!feof($in)) {
            $chunk = fread($in, self::CHUNK);
            if ($chunk === false) {
                break;
            }
            $written = $gzip ? gzwrite($out, $chunk) : fwrite($out, $chunk);
            if ($written === false || $written < strlen($chunk)) {
                fclose($in);
                $gzip ? gzclose($out) : fclose($out);
                @unlink($archive);
                throw new \RuntimeException('écriture de la copie incomplète (disque plein ?)');
            }
            $copied += strlen($chunk);
        }
        fclose($in);
        $gzip ? gzclose($out) : fclose($out);

        // Troncature sur place : pas de rename, l'écrivain externe garde son fichier et ses droits.
        $handle = @fopen($path, 'r+');
        if ($handle === false || !ftruncate($handle, 0)) {
            if ($handle !== false) {
                fclose($handle);
            }
            throw new \RuntimeException('troncature impossible (copie ' . basename($archive) . ' conservée)');
        }
        fclose($handle);

        return ['path' => $path, 'archive' => $archive, 'bytes' => $copied];
    }

    /**
     * Supprime les copies `<nom>.AAAA-MM-JJ[-HHMMSS][.gz]` plus anciennes que la rétention.
     *
     * @return list<string> Fichiers supprimés
     */
    private function purge(string $path, int $now): array
    {
        $days = $this->retentionDays();
        if ($days === 0) {
            return [];
        }
        $dir = dirname($path);
        $pattern = '/^' . preg_quote(basename($path), '/') . '\.(\d{4}-\d{2}-\d{2})(?:-\d{6})?(?:\.gz)?$/';
        $limit = date('Y-m-d', $now - $days * 86400);
        $deleted = [];
        foreach (scandir($dir) ?: [] as $name) {
            if (preg_match($pattern, $name, $m) !== 1 || $m[1] >= $limit) {
                continue;
            }
            if (@unlink($dir . '/' . $name)) {
                $deleted[] = $dir . '/' . $name;
            }
        }

        return $deleted;
    }
}

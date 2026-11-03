<?php

declare(strict_types=1);

namespace App\Mcp\Health;

/**
 * Localise les journaux du serveur SANS les lire : journal applicatif Monolog
 * (`LOG_FILE_PATH`, rotation quotidienne `nom-AAAA-MM-JJ.ext`), audit HMAC
 * (`hmac-audit*.log`), journal d'erreurs PHP (`error_log` d'`ini_get`, fichiers `error_log`
 * de cPanel à la racine et dans `public/`).
 *
 * `LOG_FILE_PATH` relatif est résolu par Monolog depuis le répertoire courant du processus :
 * la racine du dépôt pour le CRON (`cd … && php run-cron.php`), `public/` pour le web. Les
 * deux emplacements sont donc considérés.
 */
final class LogLocator
{
    private string $projectRoot;

    /** @var array<string, mixed> */
    private array $env;

    /**
     * @param array<string, mixed>|null $env Variables (défaut : $_ENV)
     */
    public function __construct(string $projectRoot, ?array $env = null, private ?string $phpErrorLog = null)
    {
        $this->projectRoot = rtrim($projectRoot, '/');
        $this->env = $env ?? $_ENV;
    }

    public function projectRoot(): string
    {
        return $this->projectRoot;
    }

    /**
     * Motifs de journaux sous la forme [dossier absolu, motif fnmatch du nom de fichier].
     *
     * @return list<array{dir: string, glob: string, kind: string}>
     */
    public function patterns(): array
    {
        $patterns = [];
        $logPath = trim((string) ($this->env['LOG_FILE_PATH'] ?? 'cronlog.txt'));
        $logPath = $logPath === '' ? 'cronlog.txt' : $logPath;
        foreach ($this->candidateDirs($logPath) as $dir) {
            $patterns[] = ['dir' => $dir, 'glob' => self::rotationGlob(basename($logPath)), 'kind' => 'applicatif'];
        }

        $hmac = trim((string) ($this->env['HMAC_AUDIT_LOG_PATH'] ?? ''));
        if ($hmac !== '') {
            foreach ($this->candidateDirs($hmac) as $dir) {
                $patterns[] = ['dir' => $dir, 'glob' => self::rotationGlob(basename($hmac)), 'kind' => 'audit_hmac'];
            }
        } else {
            foreach ($this->candidateDirs($logPath) as $dir) {
                $patterns[] = ['dir' => $dir, 'glob' => 'hmac-audit*.log', 'kind' => 'audit_hmac'];
            }
        }

        foreach ([$this->projectRoot, $this->projectRoot . '/public'] as $dir) {
            if (is_dir($dir)) {
                $patterns[] = ['dir' => $dir, 'glob' => 'error_log', 'kind' => 'erreurs_php'];
            }
        }
        $ini = $this->phpErrorLog ?? (string) ini_get('error_log');
        if ($ini !== '' && !in_array($ini, ['syslog', 'stderr'], true) && str_starts_with($ini, '/')) {
            $dir = realpath(dirname($ini));
            if ($dir !== false) {
                $patterns[] = ['dir' => $dir, 'glob' => basename($ini), 'kind' => 'erreurs_php'];
            }
        }

        $unique = [];
        foreach ($patterns as $p) {
            $unique[$p['dir'] . '|' . $p['glob']] = $p;
        }

        return array_values($unique);
    }

    /**
     * Fichiers de journaux existants, du plus récent au plus ancien.
     *
     * @return list<array{path: string, kind: string, mtime: int, size: int}>
     */
    public function files(?string $kind = null): array
    {
        $out = [];
        foreach ($this->patterns() as $p) {
            if ($kind !== null && $p['kind'] !== $kind) {
                continue;
            }
            foreach (scandir($p['dir']) ?: [] as $name) {
                $path = $p['dir'] . '/' . $name;
                if ($name === '.' || $name === '..' || !fnmatch($p['glob'], $name) || !is_file($path)) {
                    continue;
                }
                $out[$path] = ['path' => $path, 'kind' => $p['kind'], 'mtime' => (int) @filemtime($path), 'size' => (int) @filesize($path)];
            }
        }
        $out = array_values($out);
        usort($out, static fn (array $a, array $b): int => $b['mtime'] <=> $a['mtime']);

        return $out;
    }

    /**
     * @return list<string> Dossiers absolus existants
     */
    private function candidateDirs(string $path): array
    {
        $dirs = str_starts_with($path, '/')
            ? [dirname($path)]
            : [$this->projectRoot . '/' . dirname($path), $this->projectRoot . '/public/' . dirname($path)];
        $out = [];
        foreach ($dirs as $dir) {
            $real = realpath($dir);
            if ($real !== false && is_dir($real) && !in_array($real, $out, true)) {
                $out[] = $real;
            }
        }

        return $out;
    }

    /**
     * `cronlog.txt` → `cronlog*.txt` (fichier courant et rotations `cronlog-2026-11-03.txt`).
     */
    private static function rotationGlob(string $basename): string
    {
        $dot = strrpos($basename, '.');
        if ($dot === false || $dot === 0) {
            return $basename . '*';
        }

        return substr($basename, 0, $dot) . '*' . substr($basename, $dot);
    }
}

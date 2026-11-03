<?php

declare(strict_types=1);

namespace App\Mcp\Files;

use App\Mcp\Health\LogLocator;

/**
 * Racines autorisées pour la consultation de fichiers par le connecteur MCP.
 *
 * Une racine est un dossier (tout son contenu, liens résolus compris) ou un fichier unique.
 * Les chemins sont résolus par `realpath()` à la construction ; une racine inexistante est
 * ignorée. Chaque racine porte un nom court stable (`logs`, `config`, `docs`…) : l'appelant
 * désigne un fichier par `racine/chemin/relatif`, jamais par un chemin absolu.
 *
 * Liste par défaut (MCP_FILE_ROOTS vide ou contenant `defaults`) :
 *  - `logs`        : dossier du journal applicatif (LOG_FILE_PATH, rotations, hmac-audit) —
 *                    restreint aux FICHIERS DE JOURNAUX de ce dossier (pas de sous-dossier), car
 *                    c'est souvent la racine du dépôt (`cronlog.txt` par défaut) ;
 *  - `logs-public` : idem résolu depuis public/ (contexte web de Monolog), s'il diffère ;
 *  - `var`         : var/ (journaux locaux, état du CRON ; le cache compilé est exclu) ;
 *  - `php-errors`  : journal d'erreurs PHP (ini error_log, error_log de cPanel), s'il est localisable ;
 *  - `config`, `docs`, `migrations` : dossiers du dépôt ;
 *  - `.htaccess`, `run-cron.php` : fichiers uniques.
 * La sortie du CRON n'a pas de fichier propre (crontab documentée `>> /dev/null`,
 * docs/deployment/CRON.md) : elle passe par le journal applicatif (racine `logs`).
 *
 * MCP_FILE_ROOTS : CSV de chemins absolus ou relatifs à la racine du dépôt, éventuellement
 * nommés `nom=chemin` ; le mot `defaults` inclut la liste ci-dessus.
 */
final class FileRoots
{
    /** @var array<string, array{name: string, path: string, is_file: bool, logs_only: bool}> */
    private array $roots = [];

    private string $projectRoot;

    /**
     * @param list<string>|null $configured Valeur de MCP_FILE_ROOTS (null = défauts)
     */
    public function __construct(string $projectRoot, private LogLocator $logs, ?array $configured = null)
    {
        $real = realpath($projectRoot);
        $this->projectRoot = $real !== false ? $real : rtrim($projectRoot, '/');
        $entries = $configured ?? ['defaults'];
        foreach ($entries as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            if (strtolower($entry) === 'defaults') {
                foreach ($this->defaults() as $name => $path) {
                    // Racines de journaux : seuls les fichiers de journaux du premier niveau sont visibles
                    // (leur dossier peut être la racine du dépôt ou public/, cf. LOG_FILE_PATH).
                    $this->add($name, $path, in_array($name, ['logs', 'logs-public'], true));
                }
                continue;
            }
            $name = null;
            if (preg_match('/^([a-z0-9][a-z0-9_.-]{0,31})=(.+)$/i', $entry, $m) === 1) {
                [$name, $entry] = [strtolower($m[1]), trim($m[2])];
            }
            $path = str_starts_with($entry, '/') ? $entry : $this->projectRoot . '/' . $entry;
            $this->add($name ?? strtolower((string) preg_replace('/[^a-z0-9_.-]+/i', '-', basename($path))), $path);
        }
    }

    /**
     * @return array<string, array{name: string, path: string, is_file: bool, logs_only: bool}>
     */
    public function all(): array
    {
        return $this->roots;
    }

    /**
     * @return array{name: string, path: string, is_file: bool, logs_only: bool}|null
     */
    public function get(string $name): ?array
    {
        return $this->roots[$name] ?? null;
    }

    /**
     * Racine contenant un chemin RÉEL (déjà résolu), ou null s'il sort de toutes les racines.
     *
     * @return array{name: string, path: string, is_file: bool, logs_only: bool}|null
     */
    public function containing(string $realPath): ?array
    {
        foreach ($this->roots as $root) {
            if ($root['is_file'] ? $realPath === $root['path'] : ($realPath === $root['path'] || str_starts_with($realPath, $root['path'] . '/'))) {
                return $root;
            }
        }

        return null;
    }

    public function projectRoot(): string
    {
        return $this->projectRoot;
    }

    /**
     * @return array<string, string>
     */
    private function defaults(): array
    {
        $out = [];
        $appDirs = [];
        foreach ($this->logs->patterns() as $pattern) {
            if ($pattern['kind'] === 'applicatif') {
                $appDirs[] = $pattern['dir'];
            }
        }
        $appDirs = array_values(array_unique($appDirs));
        foreach ($appDirs as $i => $dir) {
            // Le dossier du journal peut être la racine du dépôt (cronlog.txt par défaut) : on
            // n'ouvre alors PAS tout le dépôt, seulement les fichiers de journaux (filtre de nom).
            $out[$i === 0 ? 'logs' : 'logs-public'] = $dir;
        }
        $out['var'] = $this->projectRoot . '/var';
        foreach ($this->logs->patterns() as $pattern) {
            if ($pattern['kind'] === 'erreurs_php' && $pattern['glob'] !== 'error_log') {
                $out['php-errors'] = $pattern['dir'] . '/' . $pattern['glob'];
            }
        }
        $out['config'] = $this->projectRoot . '/config';
        $out['docs'] = $this->projectRoot . '/docs';
        $out['migrations'] = $this->projectRoot . '/migrations';
        $out['.htaccess'] = $this->projectRoot . '/.htaccess';
        $out['run-cron.php'] = $this->projectRoot . '/run-cron.php';

        return $out;
    }

    private function add(string $name, string $path, bool $logsOnly = false): void
    {
        $real = realpath($path);
        if ($real === false || isset($this->roots[$name])) {
            return;
        }
        // Jamais la racine du système de fichiers ni un dossier de secrets connu.
        if ($real === '/' || preg_match('#/(\.ssh|\.gnupg|\.git|vendor)(/|$)#', $real) === 1) {
            return;
        }
        $isFile = is_file($real);
        $this->roots[$name] = ['name' => $name, 'path' => $real, 'is_file' => $isFile, 'logs_only' => $logsOnly && !$isFile];
    }

    /**
     * Fichiers de journaux reconnus (filtre des racines `logs_only`).
     */
    public function isLogFileName(string $basename): bool
    {
        foreach ($this->logs->patterns() as $pattern) {
            if (fnmatch($pattern['glob'], $basename)) {
                return true;
            }
        }

        return false;
    }
}

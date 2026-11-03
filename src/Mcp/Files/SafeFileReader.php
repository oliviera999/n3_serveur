<?php

declare(strict_types=1);

namespace App\Mcp\Files;

use App\Mcp\Support\SecretScanner;
use App\Mcp\ToolException;

/**
 * Lecture SEULE de fichiers du serveur pour le connecteur MCP (journaux, configuration, doc).
 *
 * Garde-fous :
 *  - désignation par `racine/chemin/relatif` uniquement (jamais de chemin absolu, octet nul refusé) ;
 *  - `realpath()` sur la cible : `..` et liens symboliques sont RÉSOLUS puis la cible réelle doit
 *    rester dans une racine autorisée ({@see FileRoots::containing()}), sinon refus ;
 *  - racines de journaux (`logs_only`) : seuls les fichiers de journaux du premier niveau y sont
 *    visibles (leur dossier peut être la racine du dépôt : le reste n'est pas exposé par ce biais) ;
 *  - dossiers et fichiers ignorés : `.git`, `vendor`, `var/cache`, `node_modules`, conteneurs de
 *    secrets (clés privées, .htpasswd…) jamais servis ;
 *  - fichiers binaires refusés (octet nul ou UTF-8 invalide dans les 8 premiers Kio) ;
 *  - `.env*` : seuls les NOMS de variables sont visibles (`CLE=****`) ; tout autre contenu passe
 *    par {@see SecretScanner::mask()} (clés API, Bearer, mots de passe d'URL, e-mails…) ;
 *  - volume borné : `max_bytes` par appel (MCP_FILE_MAX_BYTES), nombre de lignes, de fichiers
 *    parcourus et de résultats de recherche plafonnés ; regex de recherche bornée.
 *
 * Aucune écriture, aucune exécution, aucune suppression.
 */
final class SafeFileReader
{
    public const MAX_LINES = 2000;
    public const MAX_LIST_ENTRIES = 500;
    public const MAX_GREP_FILES = 200;
    public const MAX_GREP_MATCHES = 200;
    public const MAX_GREP_FILE_BYTES = 20 * 1048576;
    public const MAX_PATTERN_LENGTH = 200;
    public const MAX_LINE_LENGTH = 2000;

    private const SKIPPED_DIRS = ['.git', 'vendor', 'node_modules', '.phpunit.cache', '.phpstan.cache'];
    private const SKIPPED_PATHS = ['var/cache'];

    public function __construct(private FileRoots $roots, private int $maxBytes = 65536)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function roots(): array
    {
        $out = [];
        foreach ($this->roots->all() as $root) {
            $out[] = [
                'root' => $root['name'],
                'type' => $root['is_file'] ? 'fichier' : 'dossier',
                'logs_only' => $root['logs_only'],
            ];
        }

        return $out;
    }

    /**
     * Contenu d'un dossier (ou description d'une racine fichier).
     *
     * @return array<string, mixed>
     */
    public function list(string $path, bool $recursive, ?string $glob = null): array
    {
        [$root, $real] = $this->resolve($path);
        if (is_file($real)) {
            return ['path' => $this->display($root, $real), 'entries' => [self::public($this->entry($root, $real))], 'truncated' => false];
        }
        $entries = [];
        $truncated = false;
        $this->walk($root, $real, $recursive ? 4 : 0, static function (array $entry) use (&$entries, &$truncated, $glob): bool {
            if ($glob !== null && $entry['type'] === 'fichier' && !fnmatch($glob, basename((string) $entry['path']))) {
                return true;
            }
            if (count($entries) >= self::MAX_LIST_ENTRIES) {
                $truncated = true;

                return false;
            }
            $entries[] = self::public($entry);

            return true;
        });
        usort($entries, static fn (array $a, array $b): int => [$a['type'] === 'fichier', $a['path']] <=> [$b['type'] === 'fichier', $b['path']]);

        return ['path' => $this->display($root, $real), 'entries' => $entries, 'truncated' => $truncated];
    }

    /**
     * Lecture par lignes : `offset` (ligne de départ, 1 = première) + `limit`, ou `tail` (N dernières lignes).
     *
     * @return array<string, mixed>
     */
    public function read(string $path, ?int $offset, ?int $limit, ?int $tail, ?int $maxBytes = null): array
    {
        [$root, $real] = $this->resolve($path);
        if (!is_file($real)) {
            throw new ToolException('Ce chemin est un dossier : utiliser iot_list_files.', 'not_a_file');
        }
        $this->assertReadable($real);
        $budget = max(1024, min($this->maxBytes, $maxBytes ?? $this->maxBytes));
        $size = (int) filesize($real);
        $isEnv = SecretScanner::isEnvFile(basename($real));

        if ($tail !== null) {
            $tail = max(1, min(self::MAX_LINES, $tail));
            [$lines, $firstLine, $total] = $this->tailLines($real, $tail, $budget);
        } else {
            $offset = max(1, $offset ?? 1);
            $limit = max(1, min(self::MAX_LINES, $limit ?? 200));
            [$lines, $firstLine, $total] = $this->rangeLines($real, $offset, $limit, $budget);
        }

        $masked = 0;
        $out = [];
        foreach ($lines as $line) {
            $clean = $isEnv ? SecretScanner::maskEnvLine($line) : SecretScanner::mask($line);
            if ($clean !== $line) {
                $masked++;
            }
            $out[] = mb_strlen($clean) > self::MAX_LINE_LENGTH ? mb_substr($clean, 0, self::MAX_LINE_LENGTH) . '…' : $clean;
        }
        $lastLine = $firstLine + count($out) - 1;

        return [
            'path' => $this->display($root, $real),
            'size_bytes' => $size,
            'modified_at' => date('Y-m-d H:i:s', (int) filemtime($real)),
            'first_line' => count($out) > 0 ? $firstLine : null,
            'last_line' => count($out) > 0 ? $lastLine : null,
            'total_lines' => $total,
            'lines' => $out,
            'masked_lines' => $masked,
            'env_file' => $isEnv,
            'truncated' => $total === null || $lastLine < $total,
            'next_offset' => $total !== null && $lastLine < $total ? $lastLine + 1 : null,
            'note' => $isEnv
                ? 'Fichier d\'environnement : seuls les noms de variables sont visibles (valeurs toujours masquées).'
                : 'Les secrets reconnaissables (clés API, jetons Bearer, mots de passe d\'URL, e-mails) sont masqués.',
        ];
    }

    /**
     * Recherche d'un motif (littéral par défaut, ou regex bornée) dans un fichier ou un dossier.
     *
     * @return array<string, mixed>
     */
    public function grep(string $path, string $pattern, bool $regex, bool $caseSensitive, ?string $glob, int $maxResults, int $context): array
    {
        if ($pattern === '' || mb_strlen($pattern) > self::MAX_PATTERN_LENGTH) {
            throw new ToolException('Motif vide ou trop long (max ' . self::MAX_PATTERN_LENGTH . ' caractères).', 'invalid_pattern');
        }
        $flags = 'u' . ($caseSensitive ? '' : 'i');
        if ($regex) {
            // Regex BORNÉE : ni récursion, ni sous-routine, ni conditionnelle, ni référence arrière,
            // ni verbe de contrôle, ni modificateur en tête de motif ; quantificateurs ≤ {0,1000}.
            if (preg_match('/\(\?(?:R|\d|[+-]\d|&|P[>=]|\(|C)|\\\\[gk]|\\\\[1-9]|\(\*|\{\d{4,}|,\d{4,}\}/', $pattern) === 1) {
                throw new ToolException(
                    'Construction de regex non autorisée (récursion, référence arrière, conditionnelle, verbe de contrôle ou quantificateur > 999).',
                    'invalid_pattern'
                );
            }
            $compiled = '/' . str_replace('/', '\/', $pattern) . '/' . $flags;
        } else {
            $compiled = '/' . preg_quote($pattern, '/') . '/' . $flags;
        }
        if (@preg_match($compiled, '') === false) {
            throw new ToolException('Expression régulière invalide.', 'invalid_pattern');
        }

        [$root, $real] = $this->resolve($path);
        $files = [];
        if (is_file($real)) {
            $files[] = $real;
        } else {
            $this->walk($root, $real, 4, static function (array $entry) use (&$files, $glob): bool {
                if ($entry['type'] === 'fichier' && ($glob === null || fnmatch($glob, basename((string) $entry['path'])))) {
                    $files[] = (string) $entry['real'];
                }

                return count($files) < self::MAX_GREP_FILES;
            });
        }

        $maxResults = max(1, min(self::MAX_GREP_MATCHES, $maxResults));
        $context = max(0, min(3, $context));
        $matches = [];
        $scanned = 0;
        $skipped = [];
        $truncated = false;
        // Limite le retour arrière de PCRE : une regex pathologique échoue au lieu de bloquer.
        $previousBacktrack = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '100000');
        try {
            foreach ($files as $file) {
                if (count($matches) >= $maxResults) {
                    $truncated = true;
                    break;
                }
                try {
                    $this->assertReadable($file);
                } catch (ToolException $e) {
                    $skipped[] = ['path' => $this->display($root, $file), 'reason' => $e->errorCode()];
                    continue;
                }
                if ((int) filesize($file) > self::MAX_GREP_FILE_BYTES) {
                    $skipped[] = ['path' => $this->display($root, $file), 'reason' => 'trop_gros'];
                    continue;
                }
                $scanned++;
                $isEnv = SecretScanner::isEnvFile(basename($file));
                $handle = fopen($file, 'r');
                if ($handle === false) {
                    continue;
                }
                $buffer = [];
                $lineNo = 0;
                $pendingAfter = [];
                while (($raw = fgets($handle)) !== false) {
                    $lineNo++;
                    $line = rtrim($raw, "\r\n");
                    // On cherche sur la ligne MASQUÉE : un motif ne peut pas servir à deviner un secret.
                    $clean = $isEnv ? SecretScanner::maskEnvLine($line) : SecretScanner::mask($line);
                    $clean = mb_strlen($clean) > self::MAX_LINE_LENGTH ? mb_substr($clean, 0, self::MAX_LINE_LENGTH) . '…' : $clean;
                    foreach ($pendingAfter as $k => $idx) {
                        $matches[$idx]['after'][] = $clean;
                        if (count($matches[$idx]['after']) >= $context) {
                            unset($pendingAfter[$k]);
                        }
                    }
                    $hit = @preg_match($compiled, $clean);
                    if ($hit === false) {
                        throw new ToolException('Recherche interrompue : expression trop coûteuse.', 'pattern_too_complex');
                    }
                    if ($hit === 1) {
                        if (count($matches) >= $maxResults) {
                            $truncated = true;
                            break;
                        }
                        $matches[] = [
                            'path' => $this->display($root, $file),
                            'line' => $lineNo,
                            'text' => $clean,
                            'before' => $context > 0 ? array_slice($buffer, -$context) : [],
                            'after' => [],
                        ];
                        if ($context > 0) {
                            $pendingAfter[] = count($matches) - 1;
                        }
                    }
                    if ($context > 0) {
                        $buffer[] = $clean;
                        if (count($buffer) > $context) {
                            array_shift($buffer);
                        }
                    }
                }
                fclose($handle);
            }
        } finally {
            ini_set('pcre.backtrack_limit', (string) $previousBacktrack);
        }

        return [
            'path' => $this->display($root, $real),
            'pattern' => $pattern,
            'regex' => $regex,
            'files_scanned' => $scanned,
            'files_listed' => count($files),
            'matches' => $matches,
            'match_count' => count($matches),
            'truncated' => $truncated || count($files) >= self::MAX_GREP_FILES,
            'skipped' => array_slice($skipped, 0, 20),
            'note' => 'Recherche faite sur le texte déjà masqué (les secrets ne sont ni affichés ni cherchables).',
        ];
    }

    /**
     * Résout `racine/chemin` en chemin réel, maintenu dans une racine autorisée.
     *
     * @return array{0: array{name: string, path: string, is_file: bool, logs_only: bool}, 1: string}
     */
    public function resolve(string $path): array
    {
        $path = trim(str_replace('\\', '/', $path));
        if ($path === '' || str_contains($path, "\0")) {
            throw new ToolException('Chemin vide ou invalide. Format : <racine>/<chemin> (voir iot_list_files sans argument).', 'invalid_path');
        }
        if (str_starts_with($path, '/')) {
            throw new ToolException('Chemin absolu refusé : utiliser <racine>/<chemin relatif> (racines : iot_list_files).', 'invalid_path');
        }
        $root = null;
        $relative = '';
        // Plus long nom de racine qui préfixe le chemin (les noms peuvent contenir un point : .htaccess).
        foreach ($this->roots->all() as $candidate) {
            $name = $candidate['name'];
            if ($path === $name || str_starts_with($path, $name . '/')) {
                if ($root === null || strlen($name) > strlen($root['name'])) {
                    $root = $candidate;
                    $relative = ltrim(substr($path, strlen($name)), '/');
                }
            }
        }
        if ($root === null) {
            throw new ToolException(sprintf(
                'Racine inconnue. Racines autorisées : %s.',
                implode(', ', array_keys($this->roots->all())) ?: '(aucune)'
            ), 'unknown_root');
        }
        if ($root['is_file'] && $relative !== '') {
            throw new ToolException('Cette racine est un fichier unique.', 'invalid_path');
        }
        $candidatePath = $root['is_file'] ? $root['path'] : $root['path'] . ($relative !== '' ? '/' . $relative : '');
        $real = realpath($candidatePath);
        if ($real === false) {
            throw new ToolException('Fichier ou dossier introuvable.', 'not_found');
        }
        // Après résolution de `..` et des liens symboliques, la cible doit rester DANS sa racine.
        if (!self::within($real, $root)) {
            throw new ToolException('Accès refusé : le chemin (ou le lien symbolique) sort de la racine autorisée.', 'path_outside_root');
        }
        if ($this->isHidden($root, $real)) {
            throw new ToolException('Accès refusé : chemin exclu (dépôt git, dépendances, cache compilé ou fichier de secrets).', 'path_excluded');
        }

        return [$root, $real];
    }

    /**
     * @param array{name: string, path: string, is_file: bool, logs_only: bool} $root
     */
    private static function within(string $real, array $root): bool
    {
        return $root['is_file'] ? $real === $root['path'] : ($real === $root['path'] || str_starts_with($real, $root['path'] . '/'));
    }

    /**
     * @param array{name: string, path: string, is_file: bool, logs_only: bool} $root
     * @param callable(array<string, mixed>): bool $visit renvoie false pour arrêter
     */
    private function walk(array $root, string $dir, int $depth, callable $visit): bool
    {
        $names = @scandir($dir);
        if ($names === false) {
            return true;
        }
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $full = $dir . '/' . $name;
            $real = realpath($full);
            // Lien symbolique résolu : la cible doit rester dans la MÊME racine.
            if ($real === false || !self::within($real, $root) || $this->isHidden($root, $real)) {
                continue;
            }
            $isDir = is_dir($real);
            $entry = $this->entry($root, $full, $real);
            if (!$visit($entry)) {
                return false;
            }
            if ($isDir && $depth > 0 && !is_link($full) && !$this->walk($root, $real, $depth - 1, $visit)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array{name: string, path: string, is_file: bool, logs_only: bool} $root
     * @return array<string, mixed>
     */
    private function entry(array $root, string $path, ?string $real = null): array
    {
        $real ??= (string) realpath($path);
        $isDir = is_dir($real);

        return [
            'path' => $this->display($root, $path),
            'type' => $isDir ? 'dossier' : 'fichier',
            'size_bytes' => $isDir ? null : (int) @filesize($real),
            'modified_at' => date('Y-m-d H:i:s', (int) @filemtime($real)),
            'symlink' => is_link($path),
            'env_file' => !$isDir && SecretScanner::isEnvFile(basename($real)),
            'real' => $real,
        ];
    }

    /**
     * Entrée renvoyée au modèle : sans le chemin absolu (`real`, usage interne).
     *
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private static function public(array $entry): array
    {
        unset($entry['real']);

        return $entry;
    }

    /**
     * @param array{name: string, path: string, is_file: bool, logs_only: bool} $root
     */
    private function display(array $root, string $path): string
    {
        if ($root['is_file']) {
            return $root['name'];
        }
        $base = $root['path'];
        $normalized = str_starts_with($path, $base) ? $path : ((string) realpath($path) ?: $path);
        $relative = ltrim(substr($normalized, strlen($base)), '/');

        return $relative === '' ? $root['name'] : $root['name'] . '/' . $relative;
    }

    /**
     * @param array{name: string, path: string, is_file: bool, logs_only: bool} $root
     */
    private function isHidden(array $root, string $real): bool
    {
        if (SecretScanner::isSecretStore(basename($real))) {
            return true;
        }
        $projectRoot = $this->roots->projectRoot();
        $relativeToProject = str_starts_with($real, $projectRoot . '/') ? substr($real, strlen($projectRoot) + 1) : null;
        foreach (explode('/', $relativeToProject ?? ltrim(substr($real, strlen($root['path'])), '/')) as $segment) {
            if (in_array($segment, self::SKIPPED_DIRS, true)) {
                return true;
            }
        }
        if ($relativeToProject !== null) {
            foreach (self::SKIPPED_PATHS as $skipped) {
                if ($relativeToProject === $skipped || str_starts_with($relativeToProject, $skipped . '/')) {
                    return true;
                }
            }
        }
        // Racine de journaux : seuls les journaux du premier niveau sont visibles (ni sous-dossier,
        // ni autre fichier : le dossier peut être la racine du dépôt ou public/).
        if ($root['logs_only'] && $real !== $root['path']) {
            return dirname($real) !== $root['path'] || is_dir($real) || !$this->roots->isLogFileName(basename($real));
        }

        return false;
    }

    private function assertReadable(string $real): void
    {
        if (!is_readable($real)) {
            throw new ToolException('Fichier illisible (droits).', 'unreadable');
        }
        $handle = fopen($real, 'rb');
        if ($handle === false) {
            throw new ToolException('Fichier illisible.', 'unreadable');
        }
        $head = (string) fread($handle, 8192);
        fclose($handle);
        if (str_contains($head, "\0")) {
            throw new ToolException('Fichier binaire : lecture refusée (texte uniquement).', 'binary_file');
        }
        // UTF-8 invalide : on tolère une coupure de caractère multioctet en fin de bloc.
        if (!mb_check_encoding($head, 'UTF-8') && !mb_check_encoding(substr($head, 0, -3), 'UTF-8')) {
            if (preg_match('/[\x00-\x08\x0E-\x1F]/', $head) === 1 || !self::looksLikeLatinText($head)) {
                throw new ToolException('Fichier binaire ou encodage non textuel : lecture refusée.', 'binary_file');
            }
        }
    }

    private static function looksLikeLatinText(string $head): bool
    {
        // ISO-8859-1 / Windows-1252 (anciens journaux) : majorité de caractères imprimables.
        $printable = preg_match_all('/[\x09\x0A\x0D\x20-\x7E\xA0-\xFF]/', $head);

        return $head === '' || ($printable !== false && $printable / strlen($head) > 0.95);
    }

    /**
     * @return array{0: list<string>, 1: int, 2: int|null} [lignes, numéro de la première, total]
     */
    private function rangeLines(string $file, int $offset, int $limit, int $budget): array
    {
        $handle = fopen($file, 'r');
        if ($handle === false) {
            throw new ToolException('Fichier illisible.', 'unreadable');
        }
        $lines = [];
        $bytes = 0;
        $lineNo = 0;
        $total = 0;
        $stopped = false;
        while (($raw = fgets($handle)) !== false) {
            $lineNo++;
            $total = $lineNo;
            if ($lineNo < $offset || $stopped) {
                continue;
            }
            $line = rtrim($raw, "\r\n");
            if (count($lines) >= $limit || $bytes + strlen($line) > $budget) {
                $stopped = true;
                continue;
            }
            $lines[] = $line;
            $bytes += strlen($line) + 1;
        }
        fclose($handle);

        return [$lines, $offset, $total];
    }

    /**
     * Dernières lignes, lues depuis la fin par blocs (fichiers volumineux sans tout charger).
     *
     * @return array{0: list<string>, 1: int, 2: int|null}
     */
    private function tailLines(string $file, int $count, int $budget): array
    {
        $handle = fopen($file, 'r');
        if ($handle === false) {
            throw new ToolException('Fichier illisible.', 'unreadable');
        }
        $size = (int) filesize($file);
        $chunkSize = 8192;
        $pos = $size;
        $data = '';
        while ($pos > 0 && substr_count($data, "\n") <= $count && strlen($data) < $budget * 2) {
            $read = min($chunkSize, $pos);
            $pos -= $read;
            fseek($handle, $pos);
            $data = (string) fread($handle, $read) . $data;
        }
        fclose($handle);
        $lines = explode("\n", rtrim($data, "\r\n"));
        if ($pos > 0) {
            array_shift($lines); // première ligne probablement coupée
        }
        $lines = array_slice($lines, -$count);
        $kept = [];
        $bytes = 0;
        foreach (array_reverse($lines) as $line) {
            $line = rtrim($line, "\r");
            if ($bytes + strlen($line) > $budget) {
                break;
            }
            $kept[] = $line;
            $bytes += strlen($line) + 1;
        }
        $kept = array_reverse($kept);
        // Numéro de ligne exact si le fichier a été lu en entier ou s'il est de taille raisonnable.
        $total = $size <= 5 * 1048576 ? self::countLines($file) : null;
        $first = $total !== null ? max(1, $total - count($kept) + 1) : 0;

        return [$kept, $first, $total];
    }

    private static function countLines(string $file): int
    {
        $handle = fopen($file, 'r');
        if ($handle === false) {
            return 0;
        }
        $count = 0;
        $last = '';
        while (!feof($handle)) {
            $chunk = (string) fread($handle, 65536);
            $count += substr_count($chunk, "\n");
            if ($chunk !== '') {
                $last = $chunk;
            }
        }
        fclose($handle);

        return $count + ($last !== '' && !str_ends_with($last, "\n") ? 1 : 0);
    }
}

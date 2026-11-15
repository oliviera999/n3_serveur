<?php

declare(strict_types=1);

namespace App\Util;

use App\Service\LogService;
use Twig\Cache\FilesystemCache;

/**
 * Cache Twig sur disque qui ne fait JAMAIS échouer une page quand l'écriture d'un template compilé
 * échoue : la page est rendue sans cache (Twig compile et évalue le code en mémoire) et une ligne
 * WARNING est journalisée (une fois par fichier et par processus).
 *
 * Incident du 14/11/2026 (`GET /serre` → 500) : `RuntimeException: Failed to write cache file
 * ".../var/cache/twig/86/86718badaa41abe94bd1ac5a4d14f9a8.php"`. Sur l'hébergement mutualisé,
 * l'écriture ponctuelle peut échouer (sous-dossier de hachage créé par un autre compte — CRON SSH
 * vs PHP-FPM —, quota, course avec un vidage de cache) alors que la sonde d'écriture de
 * {@see \App\Service\TemplateRenderer::resolveWritableCacheDir()} n'essaie que la racine du cache.
 *
 * Repose sur le contrat de Twig : `Environment::loadClass()` appelle `write()` puis `load()`, et,
 * si la classe compilée n'existe toujours pas, l'évalue directement (« last line of defense »
 * prévue par Twig pour un cache no-op ou une course). Ne PAS lever ici suffit donc à rendre la page.
 * Source : twig/twig `src/Environment.php` et `src/Cache/FilesystemCache.php` (v3.x, licence
 * BSD-3-Clause) — https://github.com/twigphp/Twig/blob/3.x/src/Cache/FilesystemCache.php ;
 * l'écriture elle-même reste celle de Twig (appel à `parent::write()`).
 */
final class TolerantTwigCache extends FilesystemCache
{
    /** Âge minimal (s) d'un fichier temporaire orphelin avant nettoyage (évite la course avec un écrivain concurrent). */
    private const ORPHAN_TMP_MIN_AGE_SECONDS = 60;

    /** @var array<string, true> clés déjà signalées dans ce processus */
    private array $warned = [];

    public function __construct(string $directory, private ?LogService $logger = null, int $options = 0)
    {
        parent::__construct($directory, $options);
    }

    public function write(string $key, string $content): void
    {
        try {
            parent::write($key, $content);
        } catch (\RuntimeException $e) {
            $this->removeOrphanTempFiles($key);
            $this->warn($key, $e->getMessage());
        }
    }

    /**
     * `FilesystemCache::write()` crée d'abord un fichier temporaire (tempnam) puis le renomme :
     * après un échec (quota, renommage refusé), ce fichier reste. On supprime ceux qui ont plus
     * d'une minute (un écrivain concurrent peut être en train d'utiliser le sien).
     */
    private function removeOrphanTempFiles(string $key): void
    {
        $dir = \dirname($key);
        $pattern = $dir . '/' . basename($key) . '*';
        foreach (glob($pattern) ?: [] as $file) {
            if ($file === $key || !is_file($file)) {
                continue;
            }
            $mtime = @filemtime($file);
            if ($mtime !== false && time() - $mtime >= self::ORPHAN_TMP_MIN_AGE_SECONDS) {
                @unlink($file);
            }
        }
    }

    private function warn(string $key, string $error): void
    {
        if (isset($this->warned[$key])) {
            return;
        }
        $this->warned[$key] = true;
        $message = '[twig-cache] écriture du cache impossible, page rendue sans cache : ' . $error
            . ' (droits / quota de var/cache/twig : voir docs/deployment/CACHE_MANAGEMENT.md)';
        if ($this->logger !== null) {
            try {
                $this->logger->warning($message);

                return;
            } catch (\Throwable) {
                // Journal indisponible : repli sur error_log.
            }
        }
        error_log($message);
    }
}

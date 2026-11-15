<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Vidage SÛR d'un dossier de cache (Twig compilé, conteneur DI) pendant que le site tourne.
 *
 * Avant 6.62.0, `CacheController::clearCache()` et `bin/clear-cache.php` supprimaient TOUT
 * l'arbre, sous-dossiers compris (`rmdir`) : une requête qui venait de créer `var/cache/twig/86/`
 * pour y écrire son template compilé voyait le dossier disparaître entre son `mkdir` et son
 * `tempnam` → « Failed to write cache file » → 500 (cf. incident `GET /serre` du 14/11/2026),
 * et un fichier supprimé par une requête concurrente faisait échouer le vidage (warning `unlink`).
 *
 * Ici :
 *  - seuls les FICHIERS sont supprimés ; l'arborescence (racine + sous-dossiers de hachage) est
 *    conservée, donc jamais retirée sous les pieds d'une requête en cours ;
 *  - un fichier déjà disparu (course) n'est pas une erreur ;
 *  - la racine est (re)créée si elle manque (droits 0775 : partagés avec le groupe, CRON / web).
 *
 * Sans dépendance (utilisable par bin/clear-cache.php avant l'autoload Composer).
 */
final class CacheDirectoryPurger
{
    public const DIR_MODE = 0775;
    private const MAX_ERRORS = 5;

    /**
     * @return array{deleted: int, failed: int, created: bool, errors: list<string>}
     */
    public static function purge(string $dir): array
    {
        $result = ['deleted' => 0, 'failed' => 0, 'created' => false, 'errors' => []];

        if (is_dir($dir)) {
            try {
                $items = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::LEAVES_ONLY,
                    \RecursiveIteratorIterator::CATCH_GET_CHILD
                );
                foreach ($items as $item) {
                    if (!$item instanceof \SplFileInfo || $item->isDir()) {
                        continue;
                    }
                    $path = $item->getPathname();
                    if (@unlink($path)) {
                        $result['deleted']++;
                    } elseif (file_exists($path)) {
                        $result['failed']++;
                        if (count($result['errors']) < self::MAX_ERRORS) {
                            $result['errors'][] = 'Suppression impossible : ' . $path;
                        }
                    }
                }
            } catch (\UnexpectedValueException $e) {
                $result['failed']++;
                $result['errors'][] = 'Dossier illisible : ' . $e->getMessage();
            }
        }

        clearstatcache(true, $dir);
        if (!is_dir($dir)) {
            if (@mkdir($dir, self::DIR_MODE, true) || is_dir($dir)) {
                $result['created'] = true;
            } else {
                $result['failed']++;
                $result['errors'][] = 'Création impossible : ' . $dir;
            }
        }

        return $result;
    }
}

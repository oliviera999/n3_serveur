<?php

/**
 * Script de maintenance temporaire : vidage du cache DI et Twig via HTTP.
 *
 * Usage : accéder à https://iot.olution.info/public/maintenance/clear-di-cache.php
 * À SUPPRIMER du dépôt après utilisation (règle git-et-versionnement.mdc).
 */

declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

$projectRoot = dirname(__DIR__, 2);
$cacheDirs = [
    $projectRoot . '/var/cache/twig',
    $projectRoot . '/var/cache/di',
];

$results = [];
$totalDeleted = 0;

foreach ($cacheDirs as $cacheDir) {
    $dirName = basename($cacheDir);
    if (!is_dir($cacheDir)) {
        $results[] = "{$dirName}/ : n'existe pas, rien à vider";
        continue;
    }
    try {
        $deleted = deleteRecursive($cacheDir);
        $totalDeleted += $deleted;
        $results[] = "{$dirName}/ : {$deleted} fichier(s) supprimé(s)";
    } catch (Throwable $e) {
        $results[] = "{$dirName}/ : ERREUR - " . $e->getMessage();
    }
}

// OpCache
if (function_exists('opcache_reset') && opcache_get_status()) {
    opcache_reset();
    $results[] = 'OpCache : vidé';
} else {
    $results[] = 'OpCache : non disponible ou non activé';
}

echo "Cache DI/Twig vidé\n";
echo implode("\n", $results);
echo "\nTotal : {$totalDeleted} fichier(s)\n";
echo "À SUPPRIMER ce script après utilisation.\n";

function deleteRecursive(string $dir): int
{
    $count = 0;
    if (!is_dir($dir)) {
        return 0;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        if ($item->isDir()) {
            rmdir($item->getRealPath());
        } else {
            unlink($item->getRealPath());
            $count++;
        }
    }
    return $count;
}

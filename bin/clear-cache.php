#!/usr/bin/env php
<?php
/**
 * Script de vidage des caches de production
 * 
 * Vide les caches Twig et DI Container pour forcer la recompilation
 * après un déploiement ou une modification du code.
 * 
 * Usage: php bin/clear-cache.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/Util/CliGuard.php';
\App\Util\CliGuard::assertCli();
require_once __DIR__ . '/../src/Util/CacheDirectoryPurger.php';

// Chemins des répertoires de cache
$projectRoot = dirname(__DIR__);
$cacheDirs = [
    $projectRoot . '/var/cache/twig',
    $projectRoot . '/var/cache/di',
];

echo "\n🧹 Vidage des caches en cours...\n\n";

$totalDeleted = 0;
$errors = [];

foreach ($cacheDirs as $cacheDir) {
    $dirName = basename($cacheDir);
    
    echo "🗑️  Vidage de {$dirName}/...\n";

    // Fichiers seulement : l'arborescence est conservée (une requête web en cours peut être en
    // train d'y écrire un template compilé) — voir App\Util\CacheDirectoryPurger.
    $purge = \App\Util\CacheDirectoryPurger::purge($cacheDir);
    $totalDeleted += $purge['deleted'];
    echo "   ✅ {$purge['deleted']} fichier(s) supprimé(s)\n";
    if ($purge['created']) {
        echo "   📁 Dossier recréé\n";
    }
    foreach ($purge['errors'] as $error) {
        $errors[] = "Erreur sur {$dirName}: " . $error;
        echo "   ❌ ERREUR : " . $error . "\n";
    }
}

// Vider l'opcache PHP si disponible
echo "\n🔄 Tentative de vidage de l'OpCache PHP...\n";
$opcacheCleared = false;
$opcacheMessage = '';

if (function_exists('opcache_reset')) {
    if (opcache_get_status() && opcache_get_configuration()) {
        if (opcache_reset()) {
            $opcacheCleared = true;
            $opcacheMessage = "✅ OpCache vidé avec succès\n";
        } else {
            $opcacheMessage = "⚠️  Échec du vidage de l'OpCache\n";
        }
    } else {
        $opcacheMessage = "ℹ️  OpCache non activé\n";
    }
} else {
    $opcacheMessage = "ℹ️  OpCache non disponible (fonction opcache_reset() absente)\n";
}

echo "   {$opcacheMessage}";

echo "\n";

if (empty($errors)) {
    echo "✅ Cache vidé avec succès ! ({$totalDeleted} fichier(s) au total)\n";
    if ($opcacheCleared) {
        echo "✅ OpCache également vidé\n";
    }
    echo "ℹ️  Les caches seront régénérés automatiquement à la prochaine requête.\n\n";
    exit(0);
} else {
    echo "⚠️  Le cache a été partiellement vidé avec " . count($errors) . " erreur(s) :\n";
    foreach ($errors as $error) {
        echo "   - {$error}\n";
    }
    echo "\n";
    exit(1);
}

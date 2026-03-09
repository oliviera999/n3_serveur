<?php
/**
 * Script de maintenance : Vider le cache DI compilé
 * 
 * Ce script supprime le fichier de cache DI compilé pour forcer sa régénération.
 * À utiliser quand les définitions de dépendances ont changé dans config/dependencies.php
 * 
 * USAGE :
 * - Accéder via : https://iot.olution.info/maintenance/clear-di-cache.php
 * - Ou exécuter en CLI : php serveur/public/maintenance/clear-di-cache.php
 * 
 * SÉCURITÉ :
 * - Ce script doit être supprimé ou protégé après utilisation
 * - Ne pas laisser accessible publiquement en production
 */

declare(strict_types=1);

// Déterminer le chemin racine (2 niveaux au-dessus de public/maintenance/)
$rootDir = dirname(__DIR__, 2);
$cacheFile = $rootDir . '/var/cache/di/CompiledContainer.php';
$cacheDir = dirname($cacheFile);

header('Content-Type: text/plain; charset=utf-8');

echo "=== Nettoyage du cache DI ===\n\n";
echo "Racine serveur : $rootDir\n";
echo "Fichier cache : $cacheFile\n\n";

// Vérifier si le fichier de cache existe
if (file_exists($cacheFile)) {
    echo "✓ Fichier de cache trouvé\n";
    
    // Tenter de supprimer le fichier
    if (@unlink($cacheFile)) {
        echo "✓ Cache DI supprimé avec succès\n\n";
        echo "Le cache sera régénéré automatiquement à la prochaine requête.\n";
        echo "Vous pouvez maintenant tester les pages :\n";
        echo "  - https://iot.olution.info/aquaponie\n";
        echo "  - https://iot.olution.info/aquaponie-alt\n";
        echo "  - https://iot.olution.info/msp1/msp1datas/msp1-data.php\n";
        echo "  - https://iot.olution.info/n3pp/n3ppdatas/n3pp-data.php\n\n";
        
        // Vérifier les permissions du dossier cache
        if (is_writable($cacheDir)) {
            echo "✓ Dossier cache accessible en écriture\n";
        } else {
            echo "⚠ ATTENTION : Le dossier cache n'est pas accessible en écriture\n";
            echo "  Exécuter : chmod 755 $cacheDir\n";
        }
        
        http_response_code(200);
    } else {
        echo "✗ ERREUR : Impossible de supprimer le fichier de cache\n";
        echo "  Permissions insuffisantes ou fichier verrouillé\n";
        echo "  Essayer en SSH : rm -f $cacheFile\n";
        http_response_code(500);
    }
} else {
    echo "ℹ Fichier de cache non trouvé (déjà supprimé ou jamais généré)\n";
    
    // Vérifier si le dossier cache existe
    if (!is_dir($cacheDir)) {
        echo "⚠ Le dossier cache n'existe pas\n";
        echo "  Création du dossier...\n";
        
        if (@mkdir($cacheDir, 0755, true)) {
            echo "✓ Dossier cache créé : $cacheDir\n";
        } else {
            echo "✗ ERREUR : Impossible de créer le dossier cache\n";
            echo "  Exécuter en SSH : mkdir -p $cacheDir && chmod 755 $cacheDir\n";
        }
    } else {
        echo "✓ Dossier cache existe : $cacheDir\n";
    }
    
    http_response_code(200);
}

echo "\n=== Fin du nettoyage ===\n";
echo "\n⚠ IMPORTANT : Supprimer ce script après utilisation pour des raisons de sécurité.\n";
echo "  rm " . __FILE__ . "\n";

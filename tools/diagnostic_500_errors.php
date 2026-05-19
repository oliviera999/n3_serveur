<?php
/**
 * Script de diagnostic des erreurs 500 - FFP3
 * À exécuter sur le serveur de production
 * Usage: php tools/diagnostic_500_errors.php
 */

echo "╔═══════════════════════════════════════════════════════════════╗\n";
echo "║      DIAGNOSTIC ERREURS 500 - FFP3 AQUAPONIE                 ║\n";
echo "╚═══════════════════════════════════════════════════════════════╝\n\n";

// Charger l'autoloader
require __DIR__ . '/../vendor/autoload.php';

try {
    echo "🔍 [1/5] Test de l'environnement...\n";
    
    // Charger l'environnement
    App\Config\Env::load();
    echo "✅ Environnement chargé\n";
    
    // Test de la connexion DB
    $pdo = new PDO(
        "mysql:host={$_ENV['DB_HOST']};dbname={$_ENV['DB_NAME']};charset=utf8mb4",
        $_ENV['DB_USER'],
        $_ENV['DB_PASS']
    );
    echo "✅ Connexion DB réussie\n";
    
    echo "\n🔍 [2/5] Test du container DI...\n";
    
    // Test du container
    $container = require __DIR__ . '/../config/container.php';
    echo "✅ Container DI créé\n";
    
    echo "\n🔍 [3/5] Test des services...\n";
    
    // Test OutputService
    try {
        $outputService = $container->get(\App\Service\OutputService::class);
        $outputs = $outputService->getAllOutputs();
        echo "✅ OutputService: OK (" . count($outputs) . " outputs)\n";
    } catch (\Throwable $e) {
        echo "❌ OutputService: " . $e->getMessage() . "\n";
    }
    
    // Test Ffp3RealtimeDataProvider (RealtimeDataService était un alias déprécié, supprimé en v5.1.0)
    try {
        $realtimeService = $container->get(\App\Service\Realtime\Ffp3RealtimeDataProvider::class);
        $data = $realtimeService->getLatestReadings();
        echo "✅ Ffp3RealtimeDataProvider: OK\n";
    } catch (\Throwable $e) {
        echo "❌ Ffp3RealtimeDataProvider: " . $e->getMessage() . "\n";
    }
    
    // Test TemplateRenderer
    try {
        $renderer = $container->get(\App\Service\TemplateRenderer::class);
        echo "✅ TemplateRenderer: OK\n";
    } catch (\Throwable $e) {
        echo "❌ TemplateRenderer: " . $e->getMessage() . "\n";
    }
    
    echo "\n🔍 [4/5] Test des contrôleurs...\n";
    
    // Test OutputController
    try {
        $outputController = $container->get(\App\Controller\Ffp3\OutputController::class);
        echo "✅ OutputController: OK\n";
        
        // Test des méthodes
        $outputs = $outputController->outputService->getAllOutputs();
        echo "  └─ getAllOutputs(): OK (" . count($outputs) . " outputs)\n";
        
        $boards = $outputController->outputService->getActiveBoardsForCurrentEnvironment();
        echo "  └─ getActiveBoardsForCurrentEnvironment(): OK (" . count($boards) . " boards)\n";
        
    } catch (\Throwable $e) {
        echo "❌ OutputController: " . $e->getMessage() . "\n";
        echo "  Fichier: " . $e->getFile() . " ligne " . $e->getLine() . "\n";
    }
    
    // Test RealtimeApiController
    try {
        $realtimeController = $container->get(\App\Controller\Ffp3\RealtimeApiController::class);
        echo "✅ RealtimeApiController: OK\n";
        
        // Test des méthodes
        $data = $realtimeController->realtimeService->getLatestReadings();
        echo "  └─ getLatestReadings(): OK\n";
        
        $outputsState = $realtimeController->realtimeService->getOutputsState();
        echo "  └─ getOutputsState(): OK\n";
        
    } catch (\Throwable $e) {
        echo "❌ RealtimeApiController: " . $e->getMessage() . "\n";
        echo "  Fichier: " . $e->getFile() . " ligne " . $e->getLine() . "\n";
    }
    
    echo "\n🔍 [5/5] Test des templates...\n";
    
    // Vérifier l'existence des templates
    $templates = [
        'control.twig',
        'aquaponie.twig',
        'dashboard.twig',
        'home.twig',
        'tide_stats.twig'
    ];
    
    foreach ($templates as $template) {
        $path = __DIR__ . '/../templates/' . $template;
        if (file_exists($path)) {
            echo "✅ $template: existe\n";
        } else {
            echo "❌ $template: manquant\n";
        }
    }
    
    echo "\n╔═══════════════════════════════════════════════════════════════╗\n";
    echo "║                         RÉSUMÉ                                ║\n";
    echo "╚═══════════════════════════════════════════════════════════════╝\n\n";
    
    echo "📋 Si tous les tests passent, le problème 500 vient probablement de:\n";
    echo "1. Le routage Slim Framework\n";
    echo "2. Les middlewares\n";
    echo "3. La configuration Apache/Nginx\n";
    echo "4. Les permissions de fichiers\n\n";
    
    echo "📋 Actions recommandées:\n";
    echo "1. Vérifier les logs d'erreur: tail -f var/log/php_errors.log\n";
    echo "2. Vérifier les permissions: ls -la templates/\n";
    echo "3. Redémarrer Apache: sudo systemctl restart apache2\n";
    echo "4. Tester avec curl local: curl http://localhost/ffp3/control\n\n";
    
} catch (\Throwable $e) {
    echo "❌ ERREUR GÉNÉRALE: " . $e->getMessage() . "\n";
    echo "Fichier: " . $e->getFile() . " ligne " . $e->getLine() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
}

echo "═══════════════════════════════════════════════════════════════\n";
echo "Diagnostic terminé.\n";
?>

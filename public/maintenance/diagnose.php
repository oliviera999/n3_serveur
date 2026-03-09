<?php
/**
 * Diagnostic rapide : teste l'instanciation des contrôleurs et le rendu des templates.
 * À SUPPRIMER après utilisation.
 */
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

$rootDir = dirname(__DIR__, 2);
echo "=== Diagnostic serveur ===\n";
echo "Racine : $rootDir\n";
echo "PHP : " . PHP_VERSION . "\n";
echo "Date : " . date('Y-m-d H:i:s') . "\n";

// Version
$vf = $rootDir . '/VERSION';
echo "VERSION : " . (file_exists($vf) ? trim(file_get_contents($vf)) : 'N/A') . "\n\n";

// Autoload
$autoload = $rootDir . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    echo "ERREUR : vendor/autoload.php introuvable\n";
    exit(1);
}
require $autoload;

// Templates
echo "=== Templates ===\n";
$templates = [
    'msp1_data.twig', 'msp1_control.twig',
    'n3pp_data.twig', 'n3pp_control.twig',
    'gallery.twig', 'layout_base.twig',
    'partials/_stat_cards.twig', 'partials/_chart_init_js.twig',
    'partials/_realtime_init_js.twig',
];
$tplDir = $rootDir . '/templates';
foreach ($templates as $t) {
    $path = $tplDir . '/' . $t;
    $ok = file_exists($path) ? 'OK' : 'MANQUANT';
    echo "  $t : $ok\n";
}

// Classes
echo "\n=== Classes ===\n";
$classes = [
    'App\\Controller\\Msp\\MspDataController',
    'App\\Controller\\Msp\\MspOutputController',
    'App\\Controller\\N3pp\\N3ppDataController',
    'App\\Controller\\N3pp\\N3ppOutputController',
    'App\\Controller\\AbstractOutputController',
    'App\\Controller\\AbstractPostDataController',
    'App\\Service\\ChartDataService',
    'App\\Service\\DateRangeExtractor',
    'App\\Service\\CsvExportService',
    'App\\Util\\RealtimeUrlHelper',
    'App\\Util\\DurationFormatter',
];
foreach ($classes as $cls) {
    $ok = class_exists($cls) ? 'OK' : 'MANQUANT';
    echo "  $cls : $ok\n";
}

// DI Container
echo "\n=== Container DI ===\n";
try {
    $deps = require $rootDir . '/config/dependencies.php';
    echo "  dependencies.php : OK (" . count($deps) . " definitions)\n";
} catch (\Throwable $e) {
    echo "  dependencies.php : ERREUR - " . $e->getMessage() . "\n";
}

// Cache DI
$cacheFile = $rootDir . '/var/cache/di/CompiledContainer.php';
echo "  Cache DI : " . (file_exists($cacheFile) ? 'PRESENT' : 'absent (sera regenere)') . "\n";

// Test instantiation controllers via DI
echo "\n=== Test instantiation via DI ===\n";
try {
    $containerBuilder = new \DI\ContainerBuilder();
    $containerBuilder->addDefinitions($rootDir . '/config/dependencies.php');
    $container = $containerBuilder->build();

    $controllers = [
        'App\\Controller\\Msp\\MspDataController',
        'App\\Controller\\Msp\\MspOutputController',
        'App\\Controller\\N3pp\\N3ppDataController',
        'App\\Controller\\N3pp\\N3ppOutputController',
    ];
    foreach ($controllers as $ctrl) {
        try {
            $instance = $container->get($ctrl);
            echo "  $ctrl : OK\n";
        } catch (\Throwable $e) {
            echo "  $ctrl : ERREUR - " . $e->getMessage() . "\n";
            echo "    " . $e->getFile() . ":" . $e->getLine() . "\n";
        }
    }
} catch (\Throwable $e) {
    echo "  Container build ERREUR : " . $e->getMessage() . "\n";
}

echo "\n=== Fin diagnostic ===\n";
echo "\nSUPPRIMER ce fichier apres utilisation.\n";

<?php
/**
 * Script de diagnostic automatisé pour les contrôleurs FFP3
 * 
 * Ce script :
 * 1. Teste chaque contrôleur individuellement
 * 2. Vérifie les dépendances requises vs définies
 * 3. Capture les erreurs PHP exactes
 * 4. Génère un rapport JSON avec les corrections nécessaires
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Charger l'environnement
App\Config\Env::load();

echo "🔍 Diagnostic des contrôleurs FFP3\n";
echo "==================================\n\n";

// Configuration
$baseUrl = 'http://iot.olution.info/ffp3';
$controllers = [
    'OutputController' => [
        'endpoints' => ['/aquaponie-control', '/aquaponie-control-test', '/aquamobile-control-test', '/aquamobile-control', '/api/outputs/state', '/api/outputs-test/state'],
        'constructor' => [
            'App\Service\OutputService',
            'App\Service\TemplateRenderer', 
            'App\Repository\SensorReadRepository'
        ]
    ],
    'AquaponieController' => [
        'endpoints' => ['/aquaponie', '/aquaponie-test', '/aquamobile-test', '/aquamobile'],
        'constructor' => [
            'App\Repository\SensorReadRepository',
            'App\Service\StatisticsAggregatorService',
            'App\Service\ChartDataService',
            'App\Service\WaterBalanceService'
        ]
    ],
    'RealtimeApiController' => [
        'endpoints' => ['/api/realtime/sensors/latest', '/api/realtime-test/sensors/latest'],
        'constructor' => [
            'App\Service\RealtimeDataService'
        ]
    ],
    'DashboardController' => [
        'endpoints' => ['/dashboard', '/dashboard-test'],
        'constructor' => []
    ],
    'ExportController' => [
        'endpoints' => ['/export-data', '/export-data-test'],
        'constructor' => []
    ],
    'TideStatsController' => [
        'endpoints' => ['/tide-stats', '/tide-stats-test'],
        'constructor' => [
            'App\Service\TideAnalysisService',
            'App\Service\TemplateRenderer'
        ]
    ]
];

$results = [];
$errors = [];

echo "📋 Test des endpoints...\n\n";

foreach ($controllers as $controllerName => $config) {
    echo "🔧 Test $controllerName...\n";
    
    $controllerResults = [
        'name' => $controllerName,
        'endpoints' => [],
        'constructor_expected' => $config['constructor'],
        'status' => 'unknown'
    ];
    
    foreach ($config['endpoints'] as $endpoint) {
        echo "  📍 $endpoint: ";
        
        $url = $baseUrl . $endpoint;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        $endpointResult = [
            'url' => $url,
            'http_code' => $httpCode,
            'curl_error' => $error ?: null
        ];
        
        if ($httpCode == 200) {
            echo "✅ 200 OK\n";
            $endpointResult['status'] = 'success';
        } elseif ($httpCode == 500) {
            echo "❌ 500 Error\n";
            $endpointResult['status'] = 'error_500';
            
            // Capturer l'erreur exacte
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $body = curl_exec($ch);
            curl_close($ch);
            
            $endpointResult['error_details'] = $body;
        } else {
            echo "⚠️ $httpCode\n";
            $endpointResult['status'] = "error_$httpCode";
        }
        
        $controllerResults['endpoints'][] = $endpointResult;
    }
    
    // Déterminer le statut global du contrôleur
    $hasErrors = false;
    $hasSuccess = false;
    
    foreach ($controllerResults['endpoints'] as $endpoint) {
        if ($endpoint['status'] === 'error_500') {
            $hasErrors = true;
        } elseif ($endpoint['status'] === 'success') {
            $hasSuccess = true;
        }
    }
    
    if ($hasErrors && !$hasSuccess) {
        $controllerResults['status'] = 'failed';
    } elseif ($hasSuccess && !$hasErrors) {
        $controllerResults['status'] = 'working';
    } else {
        $controllerResults['status'] = 'partial';
    }
    
    $results[] = $controllerResults;
    
    // Résumé
    switch ($controllerResults['status']) {
        case 'working':
            echo "  ✅ Statut: FONCTIONNE\n";
            break;
        case 'failed':
            echo "  ❌ Statut: ÉCHEC TOTAL\n";
            break;
        case 'partial':
            echo "  ⚠️ Statut: PARTIEL\n";
            break;
    }
    
    echo "\n";
}

echo "📊 Résumé global\n";
echo "================\n\n";

$working = 0;
$failed = 0;
$partial = 0;

foreach ($results as $result) {
    switch ($result['status']) {
        case 'working':
            $working++;
            break;
        case 'failed':
            $failed++;
            break;
        case 'partial':
            $partial++;
            break;
    }
}

echo "✅ Contrôleurs fonctionnels: $working\n";
echo "❌ Contrôleurs en échec: $failed\n";
echo "⚠️ Contrôleurs partiels: $partial\n\n";

if ($failed > 0 || $partial > 0) {
    echo "🔧 Corrections nécessaires:\n";
    echo "===========================\n\n";
    
    foreach ($results as $result) {
        if ($result['status'] !== 'working') {
            echo "📌 $result[name]:\n";
            
            foreach ($result['endpoints'] as $endpoint) {
                if ($endpoint['status'] === 'error_500') {
                    echo "  ❌ $endpoint[url]\n";
                    if (isset($endpoint['error_details'])) {
                        // Extraire le message d'erreur principal
                        if (preg_match('/<b>Fatal error<\/b>:(.*?)<br/', $endpoint['error_details'], $matches)) {
                            echo "     Erreur: " . trim(strip_tags($matches[1])) . "\n";
                        }
                    }
                }
            }
            echo "\n";
        }
    }
}

// Sauvegarder le rapport JSON
$reportFile = __DIR__ . '/../var/log/controller-diagnostic-' . date('Y-m-d-H-i-s') . '.json';
file_put_contents($reportFile, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "📄 Rapport détaillé sauvegardé: $reportFile\n\n";

// Générer les corrections pour dependencies.php
if ($failed > 0 || $partial > 0) {
    echo "🛠️ Corrections suggérées pour config/dependencies.php:\n";
    echo "====================================================\n\n";
    
    foreach ($results as $result) {
        if ($result['status'] !== 'working' && !empty($result['constructor_expected'])) {
            echo "// $result[name]\n";
            echo "\\App\\Controller\\$result[name]::class => function (ContainerInterface \$c) {\n";
            echo "    return new \\App\\Controller\\$result[name](\n";
            
            $dependencies = $result['constructor_expected'];
            foreach ($dependencies as $i => $dep) {
                $comma = $i < count($dependencies) - 1 ? ',' : '';
                echo "        \$c->get($dep::class)$comma\n";
            }
            
            echo "    );\n";
            echo "},\n\n";
        }
    }
}

echo "🎯 Diagnostic terminé!\n";

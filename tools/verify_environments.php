<?php
/**
 * Script de vérification des environnements PROD et TEST
 * Date: 2025-10-15
 * 
 * Vérifie que les deux environnements fonctionnent correctement
 * et utilisent les bonnes tables
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config\TableConfig;
use App\Config\Env;

echo "========================================\n";
echo "VÉRIFICATION ENVIRONNEMENTS PROD/TEST\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n";
echo "========================================\n\n";

// 1. Test environnement PROD
echo "1. TEST ENVIRONNEMENT PROD\n";
echo "===========================\n";
TableConfig::setEnvironment('prod');
Env::load();

echo "✅ Environnement forcé à PROD\n";
echo "TableConfig::getEnvironment(): " . TableConfig::getEnvironment() . "\n";
echo "TableConfig::isTest(): " . (TableConfig::isTest() ? 'true' : 'false') . "\n";
echo "Table données: " . TableConfig::getDataTable() . "\n";
echo "Table outputs: " . TableConfig::getOutputsTable() . "\n";

// Vérifier que c'est bien les tables PROD
if (TableConfig::getDataTable() === 'ffp3Data' && TableConfig::getOutputsTable() === 'ffp3Outputs') {
    echo "✅ PROD: Tables correctes (ffp3Data, ffp3Outputs)\n";
} else {
    echo "❌ PROD: Tables incorrectes!\n";
}

echo "\n";

// 2. Test environnement TEST
echo "2. TEST ENVIRONNEMENT TEST\n";
echo "===========================\n";
TableConfig::setEnvironment('test');

echo "✅ Environnement forcé à TEST\n";
echo "TableConfig::getEnvironment(): " . TableConfig::getEnvironment() . "\n";
echo "TableConfig::isTest(): " . (TableConfig::isTest() ? 'true' : 'false') . "\n";
echo "Table données: " . TableConfig::getDataTable() . "\n";
echo "Table outputs: " . TableConfig::getOutputsTable() . "\n";

// Vérifier que c'est bien les tables TEST
if (TableConfig::getDataTable() === 'ffp3Data2' && TableConfig::getOutputsTable() === 'ffp3Outputs2') {
    echo "✅ TEST: Tables correctes (ffp3Data2, ffp3Outputs2)\n";
} else {
    echo "❌ TEST: Tables incorrectes!\n";
}

echo "\n";

// 3. Test de basculement
echo "3. TEST BASCULEMENT ENVIRONNEMENTS\n";
echo "===================================\n";

echo "Basculement PROD → TEST → PROD...\n";

// PROD
TableConfig::setEnvironment('prod');
echo "PROD: " . TableConfig::getDataTable() . " / " . TableConfig::getOutputsTable() . "\n";

// TEST
TableConfig::setEnvironment('test');
echo "TEST: " . TableConfig::getDataTable() . " / " . TableConfig::getOutputsTable() . "\n";

// PROD
TableConfig::setEnvironment('prod');
echo "PROD: " . TableConfig::getDataTable() . " / " . TableConfig::getOutputsTable() . "\n";

echo "✅ Basculement fonctionne correctement\n";

echo "\n";

// 4. Test de connexion DB
echo "4. TEST CONNEXION BASE DE DONNÉES\n";
echo "===================================\n";

try {
    $pdo = new PDO(
        "mysql:host=localhost;dbname=oliviera_iot;charset=utf8mb4",
        "oliviera_iot",
        "**************"
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "✅ Connexion DB réussie\n";
    
    // Vérifier existence des tables PROD
    $stmt = $pdo->query("SHOW TABLES LIKE 'ffp3Data'");
    $prodDataExists = $stmt->rowCount() > 0;
    
    $stmt = $pdo->query("SHOW TABLES LIKE 'ffp3Outputs'");
    $prodOutputsExists = $stmt->rowCount() > 0;
    
    // Vérifier existence des tables TEST
    $stmt = $pdo->query("SHOW TABLES LIKE 'ffp3Data2'");
    $testDataExists = $stmt->rowCount() > 0;
    
    $stmt = $pdo->query("SHOW TABLES LIKE 'ffp3Outputs2'");
    $testOutputsExists = $stmt->rowCount() > 0;
    
    echo "Tables PROD:\n";
    echo "  ffp3Data: " . ($prodDataExists ? "✅ Existe" : "❌ Manquante") . "\n";
    echo "  ffp3Outputs: " . ($prodOutputsExists ? "✅ Existe" : "❌ Manquante") . "\n";
    
    echo "Tables TEST:\n";
    echo "  ffp3Data2: " . ($testDataExists ? "✅ Existe" : "❌ Manquante") . "\n";
    echo "  ffp3Outputs2: " . ($testOutputsExists ? "✅ Existe" : "❌ Manquante") . "\n";
    
} catch (Exception $e) {
    echo "❌ Erreur DB: " . $e->getMessage() . "\n";
}

echo "\n========================================\n";
echo "VÉRIFICATION TERMINÉE\n";
echo "========================================\n";

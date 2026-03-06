<?php
/**
 * Script de correction environnement TEST
 * Date: 2025-10-15
 * 
 * Problème identifié: ENV=prod au lieu de ENV=test
 * Solution: Forcer l'environnement TEST pour les endpoints /post-data-test
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config\TableConfig;
use App\Config\Env;

echo "========================================\n";
echo "CORRECTION ENVIRONNEMENT TEST - FFP3\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n";
echo "========================================\n\n";

// 1. Vérifier l'état actuel
echo "1. ÉTAT ACTUEL\n";
echo "================\n";
Env::load();
echo "ENV: " . ($_ENV['ENV'] ?? 'NON DÉFINI') . "\n";
echo "TableConfig::getEnvironment(): " . TableConfig::getEnvironment() . "\n";
echo "TableConfig::isTest(): " . (TableConfig::isTest() ? 'true' : 'false') . "\n";
echo "Table données: " . TableConfig::getDataTable() . "\n";
echo "Table outputs: " . TableConfig::getOutputsTable() . "\n\n";

// 2. Forcer l'environnement TEST
echo "2. FORÇAGE ENVIRONNEMENT TEST\n";
echo "=============================\n";
TableConfig::setEnvironment('test');
echo "✅ Environnement forcé à TEST\n";
echo "TableConfig::getEnvironment(): " . TableConfig::getEnvironment() . "\n";
echo "TableConfig::isTest(): " . (TableConfig::isTest() ? 'true' : 'false') . "\n";
echo "Table données: " . TableConfig::getDataTable() . "\n";
echo "Table outputs: " . TableConfig::getOutputsTable() . "\n\n";

// 3. Test d'insertion en mode TEST
echo "3. TEST INSERTION MODE TEST\n";
echo "============================\n";

try {
    $pdo = new PDO(
        "mysql:host=localhost;dbname=oliviera_iot;charset=utf8mb4",
        "oliviera_iot",
        "**************"
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "✅ Connexion DB réussie\n";
    
    // Test insertion dans ffp3Data2
    $stmt = $pdo->prepare("
        INSERT INTO ffp3Data2 (
            sensor, version, TempAir, Humidite, TempEau,
            EauPotager, EauAquarium, EauReserve, diffMaree, Luminosite,
            etatPompeAqua, etatPompeTank, etatHeat, etatUV,
            bouffeMatin, bouffeMidi, bouffeSoir, bouffePetits, bouffeGros,
            aqThreshold, tankThreshold, chauffageThreshold,
            mail, mailNotif, bootCount, resetMode, reading_time
        ) VALUES (
            'test-script', '11.37', 28.0, 61.0, 28.0,
            209, 210, 209, -2, 228,
            1, 0, 0, 0,
            8, 12, 19, 0, 0,
            18, 80, 15,
            'test@example.com', 'checked', 1, 0, NOW()
        )
    ");
    
    $stmt->execute();
    $insertId = $pdo->lastInsertId();
    
    echo "✅ Test insertion réussi (ID: $insertId)\n";
    
    // Supprimer l'enregistrement de test
    $stmt = $pdo->prepare("DELETE FROM ffp3Data2 WHERE id = ?");
    $stmt->execute([$insertId]);
    echo "✅ Enregistrement de test supprimé\n";
    
} catch (Exception $e) {
    echo "❌ Erreur: " . $e->getMessage() . "\n";
}

echo "\n========================================\n";
echo "CORRECTION TERMINÉE\n";
echo "========================================\n";
?>

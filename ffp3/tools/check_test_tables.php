<?php
/**
 * Script de diagnostic des tables TEST
 * Date: 2025-10-15
 * 
 * Vérifie l'existence et la structure des tables TEST
 * pour diagnostiquer l'erreur HTTP 500
 */

echo "========================================\n";
echo "DIAGNOSTIC TABLES TEST - FFP3\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n";
echo "========================================\n\n";

// Configuration DB
$host = 'localhost';
$dbname = 'oliviera_iot';
$username = 'oliviera_iot';
$password = '**************'; // À remplacer par le vrai mot de passe

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "✅ Connexion DB réussie\n\n";
    
    // 1. Vérifier existence des tables TEST
    echo "1. EXISTENCE DES TABLES TEST\n";
    echo "============================\n";
    
    $tables = ['ffp3Data2', 'ffp3Outputs2', 'ffp3Heartbeat2'];
    
    foreach ($tables as $table) {
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        if ($stmt->rowCount() > 0) {
            echo "✅ Table $table existe\n";
            
            // Compter les lignes
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM $table");
            $count = $stmt->fetch()['count'];
            echo "   📊 Nombre de lignes: $count\n";
        } else {
            echo "❌ Table $table MANQUANTE\n";
        }
    }
    
    echo "\n";
    
    // 2. Vérifier structure de ffp3Data2
    echo "2. STRUCTURE DE ffp3Data2\n";
    echo "==========================\n";
    
    $stmt = $pdo->query("DESCRIBE ffp3Data2");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Colonnes trouvées:\n";
    foreach ($columns as $col) {
        echo "  - {$col['Field']} ({$col['Type']})\n";
    }
    
    echo "\n";
    
    // 3. Vérifier GPIO dans ffp3Outputs2
    echo "3. GPIO DANS ffp3Outputs2\n";
    echo "=========================\n";
    
    $stmt = $pdo->query("SELECT gpio, name, state FROM ffp3Outputs2 WHERE gpio >= 100 ORDER BY gpio");
    $gpios = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($gpios) > 0) {
        echo "GPIO trouvés:\n";
        foreach ($gpios as $gpio) {
            echo "  GPIO {$gpio['gpio']}: {$gpio['name']} = {$gpio['state']}\n";
        }
    } else {
        echo "❌ Aucun GPIO trouvé dans ffp3Outputs2\n";
    }
    
    echo "\n";
    
    // 4. Test d'insertion
    echo "4. TEST D'INSERTION\n";
    echo "===================\n";
    
    try {
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
        echo "❌ Erreur insertion: " . $e->getMessage() . "\n";
    }
    
} catch (Exception $e) {
    echo "❌ Erreur DB: " . $e->getMessage() . "\n";
}

echo "\n========================================\n";
echo "DIAGNOSTIC TERMINÉ\n";
echo "========================================\n";
?>
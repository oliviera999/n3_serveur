<?php
/**
 * Script de vérification de la configuration .env
 * 
 * Usage: php check_env.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Config\Env;
use App\Config\TableConfig;

echo "========================================\n";
echo "VÉRIFICATION CONFIGURATION .ENV\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n";
echo "========================================\n\n";

// Charger les variables d'environnement
Env::load();

echo "1. VARIABLES D'ENVIRONNEMENT\n";
echo "============================\n";

$requiredVars = [
    'ENV' => 'Environnement (prod/test)',
    'API_KEY' => 'Clé API pour authentification',
    'DB_HOST' => 'Hôte base de données',
    'DB_NAME' => 'Nom base de données',
    'DB_USER' => 'Utilisateur base de données',
    'DB_PASS' => 'Mot de passe base de données'
];

foreach ($requiredVars as $var => $description) {
    $value = $_ENV[$var] ?? null;
    if ($value !== null) {
        // Masquer les valeurs sensibles
        if (in_array($var, ['DB_PASS', 'API_KEY'])) {
            $displayValue = str_repeat('*', strlen($value));
        } else {
            $displayValue = $value;
        }
        echo "✅ $var: $displayValue ($description)\n";
    } else {
        echo "❌ $var: NON DÉFINI ($description)\n";
    }
}

echo "\n2. CONFIGURATION TABLE CONFIG\n";
echo "==============================\n";
echo "TableConfig::getEnvironment(): " . TableConfig::getEnvironment() . "\n";
echo "TableConfig::isTest(): " . (TableConfig::isTest() ? 'true' : 'false') . "\n";
echo "Table données: " . TableConfig::getDataTable() . "\n";
echo "Table outputs: " . TableConfig::getOutputsTable() . "\n";
echo "Table heartbeat: " . TableConfig::getHeartbeatTable() . "\n";

echo "\n3. FICHIER .ENV\n";
echo "================\n";
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    echo "✅ Fichier .env trouvé: $envFile\n";
    echo "Taille: " . filesize($envFile) . " bytes\n";
    echo "Dernière modification: " . date('Y-m-d H:i:s', filemtime($envFile)) . "\n";
    
    // Afficher le contenu (sans les valeurs sensibles)
    $content = file_get_contents($envFile);
    $lines = explode("\n", $content);
    echo "\nContenu du fichier .env:\n";
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0) {
            echo "$line\n";
            continue;
        }
        
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            
            // Masquer les valeurs sensibles
            if (in_array($key, ['DB_PASS', 'API_KEY'])) {
                $value = str_repeat('*', strlen($value));
            }
            
            echo "$key=$value\n";
        } else {
            echo "$line\n";
        }
    }
} else {
    echo "❌ Fichier .env non trouvé: $envFile\n";
}

echo "\n4. RECOMMANDATIONS\n";
echo "===================\n";

if (!isset($_ENV['ENV'])) {
    echo "⚠️  Ajouter ENV=test dans le fichier .env pour l'environnement TEST\n";
}

if (!isset($_ENV['API_KEY'])) {
    echo "⚠️  Ajouter API_KEY=fdGTMoptd5CD2ert3 dans le fichier .env\n";
}

if (!isset($_ENV['DB_HOST']) || !isset($_ENV['DB_NAME']) || !isset($_ENV['DB_USER']) || !isset($_ENV['DB_PASS'])) {
    echo "⚠️  Vérifier les variables de base de données (DB_HOST, DB_NAME, DB_USER, DB_PASS)\n";
}

echo "\n========================================\n";
echo "VÉRIFICATION TERMINÉE\n";
echo "========================================\n";

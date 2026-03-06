<?php
/**
 * Script de diagnostic ESP32 - Vérification complète de la chaîne de communication
 * Usage: php tools/diagnostic_esp32.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Config\Database;
use App\Config\Env;
use App\Config\TableConfig;

// Charge l'environnement
Env::load();

echo "╔═══════════════════════════════════════════════════════════════╗\n";
echo "║         DIAGNOSTIC ESP32 - COMMUNICATION SERVEUR              ║\n";
echo "╚═══════════════════════════════════════════════════════════════╝\n\n";

$errors = [];
$warnings = [];
$info = [];

// ====================================================================
// 1. VÉRIFICATION CONFIGURATION SERVEUR
// ====================================================================
echo "🔧 [1/7] Vérification Configuration Serveur...\n";

$apiKey = $_ENV['API_KEY'] ?? null;
$dbHost = $_ENV['DB_HOST'] ?? null;
$dbName = $_ENV['DB_NAME'] ?? null;
$dbUser = $_ENV['DB_USER'] ?? null;
$dbPass = $_ENV['DB_PASS'] ?? null;
$env = $_ENV['ENV'] ?? 'prod';

if (!$apiKey) {
    $errors[] = "❌ API_KEY manquante dans .env";
} else {
    $info[] = "✅ API_KEY configurée: " . substr($apiKey, 0, 5) . "***";
}

if (!$dbHost || !$dbName || !$dbUser || !$dbPass) {
    $errors[] = "❌ Configuration BDD incomplète dans .env";
} else {
    $info[] = "✅ Configuration BDD présente";
}

$info[] = "✅ Environnement actif: " . strtoupper($env);
$info[] = "✅ Table données: " . TableConfig::getDataTable();

// ====================================================================
// 2. VÉRIFICATION CONNEXION BASE DE DONNÉES
// ====================================================================
echo "🔧 [2/7] Vérification Connexion Base de Données...\n";

try {
    $pdo = Database::getConnection();
    $info[] = "✅ Connexion BDD réussie";
    
    // Test de la table
    $table = TableConfig::getDataTable();
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM {$table}");
    $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    $info[] = "✅ Table {$table} accessible ({$count} enregistrements)";
    
} catch (Exception $e) {
    $errors[] = "❌ Erreur connexion BDD: " . $e->getMessage();
}

// ====================================================================
// 3. VÉRIFICATION DERNIÈRES DONNÉES REÇUES
// ====================================================================
echo "🔧 [3/7] Vérification Dernières Données Reçues...\n";

try {
    $table = TableConfig::getDataTable();
    $stmt = $pdo->query("
        SELECT 
            reading_time, 
            sensor,
            version,
            TempAir,
            TempEau,
            EauAquarium,
            EauReserve,
            etatPompeAqua,
            etatPompeTank
        FROM {$table} 
        ORDER BY reading_time DESC 
        LIMIT 1
    ");
    
    $lastData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($lastData) {
        $lastTime = new DateTime($lastData['reading_time']);
        $now = new DateTime();
        $diff = $now->getTimestamp() - $lastTime->getTimestamp();
        $minutes = floor($diff / 60);
        
        $info[] = "📊 Dernière lecture: " . $lastData['reading_time'];
        $info[] = "    └─ Capteur: " . ($lastData['sensor'] ?? 'N/A');
        $info[] = "    └─ Version: " . ($lastData['version'] ?? 'N/A');
        $info[] = "    └─ Température Air: " . ($lastData['TempAir'] ?? 'N/A') . "°C";
        $info[] = "    └─ Température Eau: " . ($lastData['TempEau'] ?? 'N/A') . "°C";
        $info[] = "    └─ Niveau Aquarium: " . ($lastData['EauAquarium'] ?? 'N/A') . " cm";
        $info[] = "    └─ Pompe Aqua: " . ($lastData['etatPompeAqua'] ? 'ON' : 'OFF');
        
        if ($minutes < 5) {
            $info[] = "✅ DONNÉES RÉCENTES (il y a {$minutes} min)";
        } elseif ($minutes < 15) {
            $warnings[] = "⚠️  Données un peu anciennes (il y a {$minutes} min) - Tolérable";
        } else {
            $errors[] = "❌ AUCUNE DONNÉE RÉCENTE (dernière: il y a {$minutes} min / " . 
                       round($minutes/60, 1) . "h)";
            $errors[] = "   └─ L'ESP32 ne publie plus depuis plus d'une heure!";
        }
    } else {
        $errors[] = "❌ Aucune donnée trouvée dans la table {$table}";
    }
    
} catch (Exception $e) {
    $errors[] = "❌ Erreur lecture dernières données: " . $e->getMessage();
}

// ====================================================================
// 4. VÉRIFICATION ENDPOINT POST-DATA
// ====================================================================
echo "🔧 [4/7] Vérification Endpoint POST-DATA...\n";

$postDataFiles = [
    'public/post-data.php' => 'Fichier legacy direct',
    'public/index.php' => 'Router Slim',
    'src/Controller/PostDataController.php' => 'Contrôleur Slim'
];

foreach ($postDataFiles as $file => $desc) {
    if (file_exists(__DIR__ . '/../' . $file)) {
        $info[] = "✅ {$desc}: {$file}";
    } else {
        $errors[] = "❌ {$desc} manquant: {$file}";
    }
}

// ====================================================================
// 5. VÉRIFICATION LOGS RÉCENTS
// ====================================================================
echo "🔧 [5/7] Vérification Logs Récents...\n";

$logFiles = [
    'var/logs/post-data.log' => 'Logs POST données',
    'cronlog.txt' => 'Logs CRON',
    'public/error_log' => 'Logs erreurs publics',
    'error_log' => 'Logs erreurs racine'
];

foreach ($logFiles as $logFile => $desc) {
    $fullPath = __DIR__ . '/../' . $logFile;
    if (file_exists($fullPath)) {
        $size = filesize($fullPath);
        $modified = date('Y-m-d H:i:s', filemtime($fullPath));
        $info[] = "📄 {$desc}: {$logFile} ({$size} bytes, modifié: {$modified})";
        
        // Lire les dernières lignes
        if ($size > 0 && $size < 10485760) { // Si moins de 10MB
            $content = file_get_contents($fullPath);
            $lines = explode("\n", $content);
            $lastLines = array_slice($lines, -5);
            foreach ($lastLines as $line) {
                if (trim($line) !== '') {
                    $info[] = "    └─ " . substr($line, 0, 100);
                }
            }
        }
    } else {
        $warnings[] = "⚠️  {$desc} non trouvé: {$logFile}";
    }
}

// ====================================================================
// 6. VÉRIFICATION ESPACE DISQUE ET PERMISSIONS
// ====================================================================
echo "🔧 [6/7] Vérification Espace Disque et Permissions...\n";

$freeSpace = disk_free_space(__DIR__);
$totalSpace = disk_total_space(__DIR__);
$percentFree = round(($freeSpace / $totalSpace) * 100, 2);

if ($percentFree < 5) {
    $errors[] = "❌ Espace disque critique: {$percentFree}%";
} elseif ($percentFree < 10) {
    $warnings[] = "⚠️  Espace disque faible: {$percentFree}%";
} else {
    $info[] = "✅ Espace disque OK: {$percentFree}% libre";
}

// Vérifier permissions sur public/post-data.php
$postDataPath = __DIR__ . '/../public/post-data.php';
if (file_exists($postDataPath)) {
    if (is_readable($postDataPath)) {
        $info[] = "✅ public/post-data.php lisible";
    } else {
        $errors[] = "❌ public/post-data.php non lisible (permissions)";
    }
}

// ====================================================================
// 7. SIMULATION REQUÊTE ESP32
// ====================================================================
echo "🔧 [7/7] Simulation Requête ESP32 (Test API)...\n";

// Simuler une requête POST
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [
    'api_key' => $apiKey,
    'sensor' => 'DIAGNOSTIC-TEST',
    'version' => 'DIAG-1.0',
    'TempAir' => 22.5,
    'Humidite' => 65.0,
    'TempEau' => 24.0,
    'EauAquarium' => 32.0,
    'EauReserve' => 78.0,
    'EauPotager' => 45.0,
    'Luminosite' => 850,
    'etatPompeAqua' => 1,
    'etatPompeTank' => 0
];

try {
    // Test insertion
    $data = new \App\Domain\SensorData(
        sensor: $_POST['sensor'],
        version: $_POST['version'],
        tempAir: (float)$_POST['TempAir'],
        humidite: (float)$_POST['Humidite'],
        tempEau: (float)$_POST['TempEau'],
        eauPotager: (float)$_POST['EauPotager'],
        eauAquarium: (float)$_POST['EauAquarium'],
        eauReserve: (float)$_POST['EauReserve'],
        diffMaree: null,
        luminosite: (float)$_POST['Luminosite'],
        etatPompeAqua: (int)$_POST['etatPompeAqua'],
        etatPompeTank: (int)$_POST['etatPompeTank'],
        etatHeat: null,
        etatUV: null,
        bouffeMatin: null,
        bouffeMidi: null,
        bouffePetits: null,
        bouffeGros: null,
        aqThreshold: null,
        tankThreshold: null,
        chauffageThreshold: null,
        mail: null,
        mailNotif: null,
        resetMode: null,
        bouffeSoir: null
    );
    
    $repo = new \App\Repository\SensorRepository($pdo);
    $repo->insert($data);
    
    $info[] = "✅ Test insertion BDD réussi (données de test insérées)";
    
    // Vérifier que les données sont bien là
    $stmt = $pdo->query("SELECT * FROM {$table} WHERE sensor = 'DIAGNOSTIC-TEST' ORDER BY reading_time DESC LIMIT 1");
    $testData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($testData) {
        $info[] = "✅ Données de test retrouvées dans la BDD";
        
        // Nettoyer les données de test
        $pdo->exec("DELETE FROM {$table} WHERE sensor = 'DIAGNOSTIC-TEST'");
        $info[] = "✅ Données de test nettoyées";
    } else {
        $warnings[] = "⚠️  Données de test non retrouvées (possible problème de timing)";
    }
    
} catch (Exception $e) {
    $errors[] = "❌ Test insertion échoué: " . $e->getMessage();
}

// ====================================================================
// RÉSUMÉ ET RECOMMANDATIONS
// ====================================================================
echo "\n";
echo "╔═══════════════════════════════════════════════════════════════╗\n";
echo "║                    RÉSUMÉ DU DIAGNOSTIC                       ║\n";
echo "╚═══════════════════════════════════════════════════════════════╝\n\n";

if (!empty($errors)) {
    echo "❌ ERREURS CRITIQUES (" . count($errors) . "):\n";
    foreach ($errors as $error) {
        echo "   {$error}\n";
    }
    echo "\n";
}

if (!empty($warnings)) {
    echo "⚠️  AVERTISSEMENTS (" . count($warnings) . "):\n";
    foreach ($warnings as $warning) {
        echo "   {$warning}\n";
    }
    echo "\n";
}

echo "✅ INFORMATIONS (" . count($info) . "):\n";
foreach ($info as $item) {
    echo "   {$item}\n";
}

echo "\n";
echo "╔═══════════════════════════════════════════════════════════════╗\n";
echo "║                       RECOMMANDATIONS                         ║\n";
echo "╚═══════════════════════════════════════════════════════════════╝\n\n";

if (!empty($errors)) {
    echo "🔴 ACTIONS URGENTES:\n\n";
    
    if (strpos(implode(' ', $errors), 'AUCUNE DONNÉE RÉCENTE') !== false) {
        echo "1. PROBLÈME ESP32 - L'ESP32 ne publie plus!\n";
        echo "   └─ Vérifier que l'ESP32 est allumé et connecté au WiFi\n";
        echo "   └─ Vérifier les logs série de l'ESP32 (USB)\n";
        echo "   └─ Vérifier que l'ESP32 a la bonne URL: https://iot.olution.info/ffp3/public/post-data\n";
        echo "   └─ Vérifier que l'ESP32 a la bonne API_KEY: {$apiKey}\n";
        echo "   └─ Tester manuellement avec curl:\n";
        echo "      curl -X POST 'https://iot.olution.info/ffp3/public/post-data' \\\n";
        echo "        -d 'api_key={$apiKey}&sensor=TEST&TempAir=22.5'\n\n";
    }
    
    if (strpos(implode(' ', $errors), 'BDD') !== false) {
        echo "2. PROBLÈME BASE DE DONNÉES\n";
        echo "   └─ Vérifier que MySQL est démarré\n";
        echo "   └─ Vérifier les identifiants dans .env\n";
        echo "   └─ Vérifier que la table existe:\n";
        echo "      mysql -u {$dbUser} -p -e 'SHOW TABLES FROM {$dbName}'\n\n";
    }
    
    if (strpos(implode(' ', $errors), 'API_KEY') !== false) {
        echo "3. PROBLÈME CONFIGURATION\n";
        echo "   └─ Vérifier le fichier .env à la racine du projet\n";
        echo "   └─ S'assurer que API_KEY est défini\n\n";
    }
} else {
    echo "✅ Le serveur fonctionne correctement!\n\n";
    echo "Si l'ESP32 ne publie toujours pas:\n";
    echo "1. Vérifier la connectivité WiFi de l'ESP32\n";
    echo "2. Vérifier les logs série de l'ESP32\n";
    echo "3. Vérifier l'URL dans le code ESP32\n";
    echo "4. Vérifier l'API_KEY dans le code ESP32\n";
    echo "5. Augmenter le timeout HTTP sur l'ESP32 (min 10 secondes)\n";
}

echo "\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "Diagnostic terminé à " . date('Y-m-d H:i:s') . "\n";
echo "═══════════════════════════════════════════════════════════════\n";


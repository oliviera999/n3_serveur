<?php

// Point d'entrée pour la réception des données capteurs (POST)
// Ce script reçoit les données envoyées par l'ESP32 ou autre client via HTTP POST
// Il vérifie la clé API, loggue les opérations, et insère les données dans la base

require __DIR__ . '/../vendor/autoload.php';

use App\Config\Database;
use App\Domain\SensorData;
use App\Repository\SensorRepository;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

// --------------------------------------------------------------
// Initialisation du logger (journalisation des opérations)
// --------------------------------------------------------------
$logPath = __DIR__ . '/../var/logs';
if (!is_dir($logPath)) {
    mkdir($logPath, 0775, true);
}

$logger = new Logger('post-data');
$logger->pushHandler(new StreamHandler($logPath . '/post-data.log', Logger::INFO));

// Réponse en texte brut (utile pour debug ou client HTTP simple)
header('Content-Type: text/plain; charset=utf-8');

// Refuse toute requête autre que POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo "Méthode non autorisée";
    exit;
}

// --------------------------------------------------------------
// Sécurité : vérification de la clé API
// --------------------------------------------------------------
$apiKeyProvided = $_POST['api_key'] ?? '';
$apiKeyConfig   = $_ENV['API_KEY'] ?? null;
if ($apiKeyConfig === null) {
    $logger->error('La variable API_KEY est absente du .env');
    http_response_code(500);
    echo 'Configuration serveur manquante';
    exit;
}

if ($apiKeyProvided !== $apiKeyConfig) {
    $logger->warning('Tentative d\'appel avec une clé API invalide');
    http_response_code(401);
    echo 'Clé API incorrecte';
    exit;
}

// --------------------------------------------------------------
// Fonction utilitaire pour sécuriser les entrées POST
// --------------------------------------------------------------
$sanitize = static fn(string $key) => isset($_POST[$key]) ? htmlspecialchars(trim($_POST[$key])) : null;

// --------------------------------------------------------------
// Construction de l'objet métier SensorData à partir des données POST
// --------------------------------------------------------------
$data = new SensorData(
    sensor: $sanitize('sensor'),
    version: $sanitize('version'),
    tempAir: (float)$sanitize('TempAir'),
    humidite: (float)$sanitize('Humidite'),
    tempEau: (float)$sanitize('TempEau'),
    eauPotager: (float)$sanitize('EauPotager'),
    eauAquarium: (float)$sanitize('EauAquarium'),
    eauReserve: (float)$sanitize('EauReserve'),
    diffMaree: (float)$sanitize('diffMaree'),
    luminosite: (float)$sanitize('Luminosite'),
    etatPompeAqua: (int)$sanitize('etatPompeAqua'),
    etatPompeTank: (int)$sanitize('etatPompeTank'),
    etatHeat: (int)$sanitize('etatHeat'),
    etatUV: (int)$sanitize('etatUV'),
    bouffeMatin: (int)$sanitize('bouffeMatin'),
    bouffeMidi: (int)$sanitize('bouffeMidi'),
    bouffePetits: (int)$sanitize('bouffePetits'),
    bouffeGros: (int)$sanitize('bouffeGros'),
    aqThreshold: (int)$sanitize('aqThreshold'),
    tankThreshold: (int)$sanitize('tankThreshold'),
    chauffageThreshold: (int)$sanitize('chauffageThreshold'),
    mail: $sanitize('mail'),
    mailNotif: $sanitize('mailNotif'),
    resetMode: (int)$sanitize('resetMode'),
    bouffeSoir: (int)$sanitize('bouffeSoir')
);

try {
    $pdo  = Database::getConnection();
    $repo = new SensorRepository($pdo);
    $repo->insert($data);

    $logger->info('Insertion OK', ['sensor' => $data->sensor, 'version' => $data->version]);
    echo "Données enregistrées avec succès";
} catch (Throwable $e) {
    $logger->error('Erreur lors de l\'insertion', ['error' => $e->getMessage()]);
    http_response_code(500);
    echo 'Erreur serveur';
} 
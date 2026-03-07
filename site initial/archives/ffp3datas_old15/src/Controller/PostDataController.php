<?php

namespace App\Controller;

use App\Config\Database;
use App\Domain\SensorData;
use App\Repository\SensorRepository;
use App\Service\LogService;
use Throwable;

class PostDataController
{
    private LogService $logger;

    public function __construct()
    {
        $this->logger = new LogService();
    }

    /**
     * Point d'entrée HTTP : /post-data (méthode POST)
     * Vérifie la clé API, construit l'objet SensorData et insère la ligne.
     */
    public function handle(): void
    {
        header('Content-Type: text/plain; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo 'Méthode non autorisée';
            return;
        }

        $apiKeyProvided = $_POST['api_key'] ?? '';
        $apiKeyExpected = $_ENV['API_KEY'] ?? null;

        if ($apiKeyExpected === null) {
            $this->logger->error('Variable API_KEY manquante dans .env');
            http_response_code(500);
            echo 'Configuration serveur manquante';
            return;
        }

        if ($apiKeyProvided !== $apiKeyExpected) {
            $this->logger->warning("Clé API invalide depuis {ip}", ['ip' => $_SERVER['REMOTE_ADDR'] ?? 'n/a']);
            http_response_code(401);
            echo 'Clé API incorrecte';
            return;
        }

        // Fonction utilitaire pour sécuriser les entrées
        $sanitize = static fn(string $key) => isset($_POST[$key]) ? htmlspecialchars(trim($_POST[$key])) : null;

        // Construction de SensorData
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

            $this->logger->info('Données capteurs insérées', ['sensor' => $data->sensor]);
            echo 'Données enregistrées avec succès';
        } catch (Throwable $e) {
            $this->logger->error('Erreur insertion données', ['error' => $e->getMessage()]);
            http_response_code(500);
            echo 'Erreur serveur';
        }
    }
} 
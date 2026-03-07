<?php

namespace App\Controller;

use App\Config\Database;
use App\Domain\SensorData;
use App\Repository\SensorRepository;
use App\Service\LogService;
use App\Security\SignatureValidator;
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

        // ---------------------------------------------------------------------
        // Validation de la signature HMAC : facultative.
        // Si timestamp ET signature sont fournis => on valide.
        // Sinon, on laisse passer mais on loggue l’absence.
        // ---------------------------------------------------------------------
        $timestamp = $_POST['timestamp'] ?? null;
        $signature = $_POST['signature'] ?? null;

        if ($timestamp !== null || $signature !== null) {
            // Au moins un des deux champs est présent : on exige les deux + validation
            if ($timestamp === null || $signature === null) {
                $this->logger->warning('Signature partielle reçue mais incomplète', ['ip' => $_SERVER['REMOTE_ADDR'] ?? 'n/a']);
                http_response_code(401);
                echo 'Signature incomplète';
                return;
            }

            $sigSecret = $_ENV['API_SIG_SECRET'] ?? null;
            if ($sigSecret === null) {
                $this->logger->error('Variable API_SIG_SECRET manquante dans .env');
                http_response_code(500);
                echo 'Configuration serveur manquante';
                return;
            }

            $sigWindow = (int) ($_ENV['SIG_VALID_WINDOW'] ?? 300);

            if (!SignatureValidator::isValid((string) $timestamp, (string) $signature, $sigSecret, $sigWindow)) {
                $this->logger->warning('Signature HMAC invalide', ['ip' => $_SERVER['REMOTE_ADDR'] ?? 'n/a']);
                http_response_code(401);
                echo 'Signature incorrecte';
                return;
            }
            // Signature OK
        } else {
            // Pas de signature → mode compatibilité
            $this->logger->info('Aucune signature fournie – fallback API_KEY');
        }

        // ---------------------------------------------------------------------
        // Validation de la clé API (mécanisme legacy)
        // ---------------------------------------------------------------------
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

        // Fonctions utilitaires de lecture POST --------------------------------
        // Valeur brute (chaîne) ou null si absente / vide
        $sanitize = static fn(string $key) => isset($_POST[$key]) && $_POST[$key] !== '' ? trim($_POST[$key]) : null;
        // Conversions typées sûres (retournent null si champ manquant)
        $toFloat = static fn(string $key) => isset($_POST[$key]) && $_POST[$key] !== '' ? (float) $_POST[$key] : null;
        $toInt   = static fn(string $key) => isset($_POST[$key]) && $_POST[$key] !== '' ? (int) $_POST[$key] : null;

        // Construction de l'objet transférant les données capteurs -------------
        $data = new SensorData(
            sensor: $sanitize('sensor'),
            version: $sanitize('version'),
            tempAir: $toFloat('TempAir'),
            humidite: $toFloat('Humidite'),
            tempEau: $toFloat('TempEau'),
            eauPotager: $toFloat('EauPotager'),
            eauAquarium: $toFloat('EauAquarium'),
            eauReserve: $toFloat('EauReserve'),
            diffMaree: $toFloat('diffMaree'),
            luminosite: $toFloat('Luminosite'),
            etatPompeAqua: $toInt('etatPompeAqua'),
            etatPompeTank: $toInt('etatPompeTank'),
            etatHeat: $toInt('etatHeat'),
            etatUV: $toInt('etatUV'),
            bouffeMatin: $toInt('bouffeMatin'),
            bouffeMidi: $toInt('bouffeMidi'),
            bouffePetits: $toInt('bouffePetits'),
            bouffeGros: $toInt('bouffeGros'),
            aqThreshold: $toInt('aqThreshold'),
            tankThreshold: $toInt('tankThreshold'),
            chauffageThreshold: $toInt('chauffageThreshold'),
            mail: $sanitize('mail'),
            mailNotif: $sanitize('mailNotif'),
            resetMode: $toInt('resetMode'),
            bouffeSoir: $toInt('bouffeSoir')
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
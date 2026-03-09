<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\LogService;
use App\Util\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

/**
 * Flux commun de réception des données POST firmware ESP32.
 *
 * Sous-classes : PostDataController (FFP3), MspPostDataController (MSP1), N3ppPostDataController (N3PP).
 * Chaque sous-classe implémente buildSensorData() et insertData().
 */
abstract class AbstractPostDataController
{
    public function __construct(
        protected LogService $logger,
    ) {
    }

    /**
     * Nom court du composant (pour les logs).
     */
    abstract protected function componentName(): string;

    /**
     * Construit l'objet de données capteur à partir des paramètres sanitisés.
     *
     * @param array $params Paramètres bruts du body
     * @param \Closure(string):?string $sanitize
     * @param \Closure(string):?float $toFloat
     * @param \Closure(string):?int $toInt
     * @return object L'objet domaine (SensorData, MspSensorData, N3ppSensorData)
     */
    abstract protected function buildSensorData(array $params, \Closure $sanitize, \Closure $toFloat, \Closure $toInt): object;

    /**
     * Insère les données et effectue les traitements post-insertion.
     *
     * @param object $data L'objet domaine
     * @return void
     */
    abstract protected function insertData(object $data): void;

    /**
     * Validation supplémentaire avant le flux standard (ex. HMAC pour FFP3).
     * Retourne null si OK, une Response si KO.
     */
    protected function validateAuth(array $params, Response $response): ?Response
    {
        return null;
    }

    public function handle(Request $request, Response $response): Response
    {
        set_time_limit(30);

        $component = $this->componentName();

        if ($request->getMethod() !== 'POST') {
            $this->logger->warning("{$component}: Methode non autorisee", ['method' => $request->getMethod()]);
            return ResponseHelper::text($response, 'Methode non autorisee', 405);
        }

        $params = $request->getParsedBody();
        if (!is_array($params)) {
            $params = [];
        }

        // Hook d'authentification spécifique (HMAC, etc.)
        $authResult = $this->validateAuth($params, $response);
        if ($authResult !== null) {
            return $authResult;
        }

        // Validation clé API (legacy, commune à toutes les sections)
        $apiKeyProvided = $params['api_key'] ?? '';
        $apiKeyExpected = $_ENV['API_KEY'] ?? null;

        if ($apiKeyExpected === null) {
            $this->logger->error("{$component}: Variable API_KEY manquante dans .env");
            return ResponseHelper::text($response, 'Configuration serveur manquante', 500);
        }

        if ($apiKeyProvided !== $apiKeyExpected) {
            $this->logger->warning("{$component}: Cle API invalide", ['ip' => $_SERVER['REMOTE_ADDR'] ?? 'n/a']);
            return ResponseHelper::text($response, 'Cle API incorrecte', 401);
        }

        // Fonctions utilitaires de lecture POST
        $sanitize = static function (string $key) use ($params): ?string {
            if (!isset($params[$key]) || !is_scalar($params[$key])) {
                return null;
            }
            $v = trim((string) $params[$key]);
            return $v !== '' ? $v : null;
        };
        $toFloat = static function (string $key) use ($params): ?float {
            if (!isset($params[$key]) || !is_scalar($params[$key]) || $params[$key] === '') {
                return null;
            }
            $f = (float) $params[$key];
            return is_finite($f) ? $f : null;
        };
        $toInt = static fn(string $key) => isset($params[$key]) && is_scalar($params[$key]) && $params[$key] !== ''
            ? (int) $params[$key] : null;

        // Validation champs requis
        $sensor = $sanitize('sensor');
        $version = $sanitize('version');
        if ($sensor === null || $version === null) {
            return ResponseHelper::text($response, 'Champs requis manquants: sensor, version', 400);
        }

        $data = $this->buildSensorData($params, $sanitize, $toFloat, $toInt);

        try {
            $this->insertData($data);

            $this->logger->info("{$component} OK sensor={sensor} version={version}", [
                'sensor' => $data->sensor,
                'version' => $data->version,
            ]);

            return ResponseHelper::textClose($response, 'Donnees enregistrees avec succes', 200);
        } catch (Throwable $e) {
            $this->logger->error("{$component}: Erreur insertion: {error}", ['error' => $e->getMessage()]);
            return ResponseHelper::text($response, 'Erreur serveur', 500);
        }
    }
}

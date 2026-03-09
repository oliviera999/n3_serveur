<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\LogService;
use App\Util\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Flux commun de réception des données POST des firmwares.
 * Chaque module (FFP3, MSP1, N3PP) hérite et fournit :
 *   - componentName(), buildSensorData(), insertData()
 */
abstract class AbstractPostDataController
{
    public function __construct(
        protected LogService $logger
    ) {}

    abstract protected function componentName(): string;

    /**
     * Construit le DTO capteur à partir des paramètres POST sanitisés.
     */
    abstract protected function buildSensorData(
        array $params,
        \Closure $sanitize,
        \Closure $toFloat,
        \Closure $toInt
    ): object;

    /**
     * Persiste les données et exécute les effets de bord (sync outputs, cache, etc.).
     */
    abstract protected function insertData(object $data): void;

    /**
     * Validation d'authentification. Par défaut : clé API legacy.
     * FFP3 surcharge avec HMAC + fallback.
     *
     * @return Response|null null = OK, Response = rejet
     */
    protected function validateAuth(array $params, Response $response): ?Response
    {
        return null;
    }

    /**
     * Flux principal : validation, sanitisation, construction DTO, insertion.
     */
    public function handle(Request $request, Response $response): Response
    {
        $component = $this->componentName();

        if ($request->getMethod() !== 'POST') {
            return ResponseHelper::text($response, 'POST requis', 405);
        }

        $params = $request->getParsedBody();
        if (!is_array($params) || $params === []) {
            $this->logger->warning("{$component}: corps vide ou invalide");
            return ResponseHelper::text($response, 'Donnees manquantes', 400);
        }

        // Authentification (HMAC ou API_KEY selon le module)
        $authError = $this->validateAuth($params, $response);
        if ($authError !== null) {
            return $authError;
        }

        $apiKey = isset($params['api_key']) ? trim((string) $params['api_key']) : '';
        $expectedKey = $_ENV['API_KEY'] ?? '';
        if ($expectedKey !== '' && $apiKey !== $expectedKey) {
            $this->logger->warning("{$component}: cle API invalide", ['ip' => $_SERVER['REMOTE_ADDR'] ?? 'n/a']);
            return ResponseHelper::text($response, 'Cle API invalide', 401);
        }

        $sensor = trim((string) ($params['sensor'] ?? ''));
        $version = trim((string) ($params['version'] ?? ''));
        if ($sensor === '' || $version === '') {
            $this->logger->warning("{$component}: champs sensor/version manquants");
            return ResponseHelper::text($response, 'Champs sensor et version requis', 400);
        }

        $sanitize = fn(string $key): ?string =>
            isset($params[$key]) && is_scalar($params[$key])
                ? trim((string) $params[$key])
                : null;

        $toFloat = fn(string $key): ?float =>
            isset($params[$key]) && is_numeric($params[$key])
                ? (float) $params[$key]
                : null;

        $toInt = fn(string $key): ?int =>
            isset($params[$key]) && is_numeric($params[$key])
                ? (int) $params[$key]
                : null;

        try {
            $sensorData = $this->buildSensorData($params, $sanitize, $toFloat, $toInt);
            $this->insertData($sensorData);

            $this->logger->info("{$component}: donnees enregistrees sensor={sensor} v={version}", [
                'sensor' => $sensor,
                'version' => $version,
            ]);

            return ResponseHelper::textClose($response, 'Donnees enregistrees avec succes', 200);
        } catch (\Throwable $e) {
            $this->logger->error("{$component}: erreur insertion — {msg}", ['msg' => $e->getMessage()]);
            return ResponseHelper::text($response, 'Erreur serveur', 500);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Concerns\HmacPolicyTrait;
use App\Controller\Concerns\OperationalSettingsTrait;
use App\Middleware\RawPostBodyMiddleware;
use App\Security\RateLimiter;
use App\Service\HmacAuditLogger;
use App\Service\HmacPolicyService;
use App\Service\LogService;
use App\Service\OperationalSettingsService;
use App\Util\ClientIpResolver;
use App\Util\RequestHelper;
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
    use HmacPolicyTrait;
    use OperationalSettingsTrait;

    /** True si l'authentification HMAC FFP3 a réussi (évite le double contrôle api_key). */
    protected bool $authenticatedByHmac = false;

    public function __construct(
        protected LogService $logger,
        protected ?HmacAuditLogger $hmacAuditLogger = null,
        ?HmacPolicyService $hmacPolicyService = null,
        ?OperationalSettingsService $operationalSettings = null,
    ) {
        $this->hmacPolicyService = $hmacPolicyService;
        $this->operationalSettings = $operationalSettings;
    }

    /**
     * @param array<string, scalar|null> $context
     */
    protected function recordHmacAudit(
        string $result,
        string $authMode,
        array $context = [],
        ?string $reason = null
    ): void {
        $this->hmacAuditLogger?->record($this->componentName(), $result, $authMode, $context, $reason);
    }

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
     * Hook après validation corps + auth + champs obligatoires (ex. dédup post_id côté FFP3).
     */
    protected function afterValidatedParams(Request $request, Response $response, array $params): ?Response
    {
        return null;
    }

    /**
     * Rate-limiting optionnel par IP (défaut DÉSACTIVÉ). Actif uniquement si
     * `FIRMWARE_RATE_LIMIT_MAX` > 0 dans l'environnement (fenêtre
     * `FIRMWARE_RATE_LIMIT_WINDOW`, défaut 60 s). Généreux : un capteur POST
     * ~1×/5 min, un flood est borné. Fail-open (RateLimiter ne bloque jamais
     * si le stockage est indisponible).
     */
    private function enforceFirmwareRateLimit(Request $request, Response $response, string $component): ?Response
    {
        $max = $this->opInt('FIRMWARE_RATE_LIMIT_MAX', 0);
        if ($max <= 0) {
            return null;
        }
        $window = $this->opInt('FIRMWARE_RATE_LIMIT_WINDOW', 60);
        if ($window <= 0) {
            $window = 60;
        }

        // X-Forwarded-For n'est cru que derrière un proxy déclaré dans TRUSTED_PROXIES :
        // sinon un client faisant varier l'en-tête obtiendrait un compteur neuf à chaque
        // requête, rendant la limite inopérante (corrigé en 6.34.0).
        $ip = ClientIpResolver::resolve($request);

        $limiter = new RateLimiter();
        if ($limiter->hit("firmware:{$component}:{$ip}", $window) > $max) {
            $this->logger->warning("{$component}: rejet rate limit code=429", ['ip' => $ip]);
            return ResponseHelper::text($response, 'Trop de requetes', 429);
        }

        return null;
    }

    /**
     * Permet à un module d'ajouter du contexte d'auth dépendant de la requête brute
     * avant validateAuth() (ex. signature HMAC portée par des en-têtes).
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected function prepareParamsForAuth(Request $request, array $params): array
    {
        return $params;
    }

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
     * Si false après validateAuth, la clé API legacy n'est pas exigée (ex. HMAC FFP3 valide).
     */
    protected function requiresApiKey(): bool
    {
        return !$this->authenticatedByHmac;
    }

    /**
     * Flux principal : validation, sanitisation, construction DTO, insertion.
     */
    public function handle(Request $request, Response $response): Response
    {
        $this->authenticatedByHmac = false;
        $component = $this->componentName();

        if ($request->getMethod() !== 'POST') {
            $this->logger->warning("{$component}: rejet method={method} code=405", [
                'method' => $request->getMethod(),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'n/a',
            ]);
            return ResponseHelper::text($response, 'POST requis', 405);
        }

        $rlError = $this->enforceFirmwareRateLimit($request, $response, $component);
        if ($rlError !== null) {
            return $rlError;
        }

        $rawBody = $request->getAttribute(RawPostBodyMiddleware::ATTRIBUTE);
        if (!is_string($rawBody)) {
            $rawBody = (string) $request->getBody();
        }
        $request = $request->withAttribute(RawPostBodyMiddleware::ATTRIBUTE, $rawBody);

        $params = RequestHelper::extractParams($request, $rawBody);
        if ($params === []) {
            $this->logger->warning("{$component}: rejet corps vide code=400", [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'n/a',
            ]);
            return ResponseHelper::text($response, 'Donnees manquantes', 400);
        }
        $params = $this->prepareParamsForAuth($request, $params);

        // Authentification (HMAC ou API_KEY selon le module)
        $authStart = microtime(true);
        $authError = $this->validateAuth($params, $response);
        $authMs = (int) round((microtime(true) - $authStart) * 1000);
        $this->logger->info("{$component}: auth_ms={authMs}", [
            'auth_ms' => $authMs,
            'sensor' => trim((string) ($params['sensor'] ?? '')),
            'version' => trim((string) ($params['version'] ?? '')),
            'hmac_ok' => $this->authenticatedByHmac,
        ]);
        if ($authError !== null) {
            return $authError;
        }

        if ($this->requiresApiKey()) {
            $apiKey = isset($params['api_key']) ? trim((string) $params['api_key']) : '';
            $expectedKey = $_ENV['API_KEY'] ?? '';
            if ($expectedKey === '') {
                $this->logger->error("{$component}: rejet auth API_KEY non configuree code=500", [
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'n/a',
                ]);
                return ResponseHelper::text($response, 'Configuration serveur manquante', 500);
            }
            if (!hash_equals($expectedKey, $apiKey)) {
                $this->logger->warning("{$component}: rejet auth api_key code=401", [
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'n/a',
                    'sensor' => trim((string) ($params['sensor'] ?? '')),
                    'version' => trim((string) ($params['version'] ?? '')),
                    'post_id' => isset($params['post_id']) ? substr(trim((string) $params['post_id']), 0, 64) : null,
                ]);
                return ResponseHelper::text($response, 'Cle API invalide', 401);
            }
        }

        $sensor = trim((string) ($params['sensor'] ?? ''));
        $version = trim((string) ($params['version'] ?? ''));
        if ($sensor === '' || $version === '') {
            $this->logger->warning("{$component}: rejet validation sensor/version code=400", [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'n/a',
                'has_sensor' => isset($params['sensor']),
                'has_version' => isset($params['version']),
                'post_id' => isset($params['post_id']) ? substr(trim((string) $params['post_id']), 0, 64) : null,
            ]);
            return ResponseHelper::text($response, 'Champs sensor et version requis', 400);
        }

        $hookResp = $this->afterValidatedParams($request, $response, $params);
        if ($hookResp !== null) {
            return $hookResp;
        }

        $sanitize = fn (string $key): ?string =>
            isset($params[$key]) && is_scalar($params[$key])
                ? trim((string) $params[$key])
                : null;

        $toFloat = fn (string $key): ?float =>
            isset($params[$key]) && is_numeric($params[$key])
                ? (float) $params[$key]
                : null;

        $toInt = fn (string $key): ?int =>
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
            $this->logger->error("{$component}: rejet exception code=500", [
                'msg' => $e->getMessage(),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'n/a',
                'sensor' => $sensor ?? '',
                'version' => $version ?? '',
                'post_id' => isset($params['post_id']) ? substr(trim((string) $params['post_id']), 0, 64) : null,
                'trace' => $e->getTraceAsString(),
            ]);
            return ResponseHelper::text($response, 'Erreur serveur', 500);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Controller;

use App\Config\TableConfig;
use App\Controller\Traits\HandlesNotificationPolicy;
use App\Repository\AbstractOutputRepository;
use App\Security\AuthService;
use App\Service\ControlAuditLogger;
use App\Service\FirmwareStateCompat;
use App\Service\LogService;
use App\Service\OperationalSettingsService;
use App\Service\TemplateRenderer;
use App\Util\RequestHelper;
use App\Util\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Contrôleur abstrait pour les pages de contrôle GPIO/outputs (MSP1, N3PP).
 * FFP3 conserve son propre OutputController (logique OTA, cache, multi-boards).
 */
abstract class AbstractOutputController
{
    use HandlesNotificationPolicy;

    protected ControlAuditLogger $auditLogger;

    protected ?OperationalSettingsService $operationalSettings = null;

    public function __construct(
        protected LogService $logger,
        protected TemplateRenderer $renderer,
        protected AuthService $authService,
        ?ControlAuditLogger $auditLogger = null,
        ?OperationalSettingsService $operationalSettings = null,
    ) {
        // Les sous-classes (MSP/N3pp) appellent parent::__construct() avec 3 arguments :
        // on construit alors l'audit logger à partir des dépendances déjà fournies.
        $this->auditLogger = $auditLogger ?? new ControlAuditLogger($logger, $authService);
        $this->operationalSettings = $operationalSettings;
    }

    /**
     * Nom de module utilisé dans les entrées d'audit (ffp3, msp, n3pp).
     * Dérivé du componentName() par défaut ; surcharge possible.
     */
    protected function auditModule(): string
    {
        $component = strtolower($this->componentName());
        if (str_contains($component, 'msp')) {
            return 'msp';
        }
        if (str_contains($component, 'n3pp')) {
            return 'n3pp';
        }
        return $component;
    }

    /**
     * Ensemble canonique des GPIO autorisés en écriture pour le module (actionneurs +
     * configuration + commandes one-shot). Validé AVANT toute persistance.
     *
     * Construit sans dépendre des repos (non modifiables) : actionneurs physiques communs
     * (2/12/13/15/16/18), GPIO de configuration (100..116) et commandes one-shot (108/109/110)
     * couvrent l'intégralité des cibles légitimes des modules MSP/N3pp.
     *
     * @return list<int>
     */
    protected function allowedGpios(): array
    {
        $gpios = [2, 12, 13, 15, 16, 18];
        for ($gpio = 100; $gpio <= 116; $gpio++) {
            $gpios[] = $gpio;
        }
        return $gpios;
    }

    protected function isGpioAllowed(int $gpio): bool
    {
        return in_array($gpio, $this->allowedGpios(), true);
    }

    abstract protected function defaultBoard(): int;
    abstract protected function componentName(): string;
    abstract protected function controlTemplate(): string;

    /**
     * Données spécifiques pour le template de la page contrôle.
     */
    abstract protected function buildControlPageData(int $board): array;

    /**
     * Construit la structure control_config commune (sidebar, titres, etc.).
     */
    protected function makeControlConfig(
        string $testEnv,
        string $sidebarTitle,
        string $sidebarDescription,
        int $outputsCount,
        string $icon,
        string $mainTitle,
        string $mainDescription,
        string $defaultApiBase,
        ?string $warningText = null
    ): array {
        return [
            'test_env' => $testEnv,
            'sidebar_title' => $sidebarTitle,
            'sidebar_description' => $sidebarDescription,
            'outputs_count' => $outputsCount,
            'icon' => $icon,
            'main_title' => $mainTitle,
            'main_description' => $mainDescription,
            'default_api_base' => $defaultApiBase,
            'warning_text' => $warningText,
        ];
    }

    /**
     * Préambule commun de buildControlPageData : résout l'environnement courant et les
     * bases d'URL API (outputs/realtime) selon que l'on est dans l'environnement de test
     * du module. `$slug` est le préfixe de route du module ('n3pp', 'msp1').
     *
     * @return array{env: string, is_test: bool, outputs_api_base: string, realtime_api_base: string}
     */
    protected function resolveControlEnv(string $testEnv, string $slug): array
    {
        $env = TableConfig::getEnvironment();
        $isTest = $env === $testEnv;

        return [
            'env' => $env,
            'is_test' => $isTest,
            'outputs_api_base' => $isTest ? "/{$slug}-test/api/outputs" : "/{$slug}/api/outputs",
            'realtime_api_base' => $isTest ? "/{$slug}-test/api/realtime" : "/{$slug}/api/realtime",
        ];
    }

    /**
     * Un output est un actionneur physique lorsque son GPIO est < 100 (les GPIO ≥ 100 sont
     * des paramètres / commandes virtuelles). Prédicat commun MSP/N3pp.
     *
     * @param array<string, mixed> $output
     */
    protected function isActuatorOutput(array $output): bool
    {
        return (int) ($output['gpio'] ?? 0) < 100;
    }

    /**
     * Filtre les outputs pour ne conserver que les actionneurs physiques (GPIO < 100).
     *
     * @param array<int, array<string, mixed>> $outputs
     *
     * @return array<int, array<string, mixed>>
     */
    protected function filterActuatorOutputs(array $outputs): array
    {
        return array_values(array_filter(
            $outputs,
            fn (array $output): bool => $this->isActuatorOutput($output)
        ));
    }

    /**
     * POST : sauvegarde la politique de notifications. Route commune MSP/N3pp ;
     * délègue au trait HandlesNotificationPolicy (la famille est fournie par la sous-classe).
     */
    public function saveNotificationPolicy(Request $request, Response $response): Response
    {
        return $this->updateNotificationPolicy($request, $response);
    }

    /**
     * Repository d'outputs du module (N3pp/Msp), tous deux dérivés d'AbstractOutputRepository.
     * Fourni par la sous-classe (propriété privée câblée par le constructeur / PHP-DI) afin
     * que la base puisse factoriser les délégations d'accès aux données sans posséder la
     * dépendance concrète.
     */
    abstract protected function outputRepository(): AbstractOutputRepository;

    /**
     * Retourne l'état des sorties pour le firmware (format dépend du module).
     */
    protected function getStateData(int $board): array
    {
        $flat = $this->outputRepository()->getStateForFirmware($board);

        // Compatibilité flotte déployée : cette route firmware reste TOUJOURS servie
        // (contrairement à la fusion de clés plates dans /api/outputs/state, pilotée par
        // FIRMWARE_FLAT_STATE_MODE), mais son contenu est nettoyé hors mode `full` :
        // GPIO d'état miroir retirés et valeurs de config hors plage omises.
        // Cf. App\Service\FirmwareStateCompat.
        return (new FirmwareStateCompat($this->operationalSettings))
            ->sanitize($flat, $this->firmwareModule());
    }

    /**
     * Module firmware au sens de {@see FirmwareStateCompat} ('n3pp', 'msp1'),
     * dérivé du module d'audit ('' si inconnu → seules les bornes communes s'appliquent).
     */
    protected function firmwareModule(): string
    {
        return match ($this->auditModule()) {
            'n3pp' => FirmwareStateCompat::MODULE_N3PP,
            'msp' => FirmwareStateCompat::MODULE_MSP1,
            default => '',
        };
    }

    /**
     * Exécute le toggle d'un output. Retourne un tableau avec 'success' et éventuellement 'error'/'status'.
     */
    abstract protected function doToggle(array $params, int $board): array;

    /**
     * Exécute la mise à jour legacy action=set.
     */
    abstract protected function doSetOutput(array $params, int $board): array;

    /**
     * Liste des clés de paramètres supportées par le module.
     *
     * @return string[]
     */
    abstract protected function getDefaultParamKeys(): array;

    protected function updateParameterByName(int $board, string $paramName, string $value): bool
    {
        return $this->outputRepository()->updateParameterByName($board, $paramName, $value);
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function batchUpdateParameters(int $board, array $params): void
    {
        $this->outputRepository()->batchUpdateParameters($board, $params);
    }

    /**
     * Normalise une valeur de state brute vers '0' ou '1'.
     */
    protected function normalizeOutputState(string $state): string
    {
        return in_array($state, ['1', '1.00'], true) ? '1' : '0';
    }

    /**
     * Traitement des actions legacy (surcharge optionnelle).
     */
    protected function handleLegacyAction(Request $request, Response $response, string $action): Response
    {
        return ResponseHelper::json($response, ['error' => 'Action inconnue'], 400);
    }

    /**
     * Affiche la page de contrôle.
     */
    public function showControlPage(Request $request, Response $response): Response
    {
        if (!$this->authService->isAuthenticated()
            && !$this->authService->isAuthenticatedByToken($request->getQueryParams())) {
            $redirect = '/login?redirect=' . urlencode($request->getUri()->getPath());

            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        try {
            $board = $this->defaultBoard();
            $data = $this->buildControlPageData($board);
            $html = $this->renderer->render($this->controlTemplate(), $data);
            $response->getBody()->write($html);
            return $response;
        } catch (\Throwable $e) {
            $this->logger->error("{$this->componentName()}: erreur showControlPage — {msg}", ['msg' => $e->getMessage()]);
            return ResponseHelper::text($response, 'Erreur serveur', 500);
        }
    }

    /**
     * GET legacy : retourne l'état des outputs pour le firmware.
     */
    public function getState(Request $request, Response $response): Response
    {
        $keyError = $this->enforceFirmwareStateKey($request, $response);
        if ($keyError !== null) {
            return $keyError;
        }

        $queryParams = $request->getQueryParams();
        $action = $queryParams['action'] ?? '';

        if ($action !== '' && $action !== 'outputs_state') {
            return $this->handleLegacyAction($request, $response, $action);
        }

        $board = (int) ($queryParams['board'] ?? $this->defaultBoard());

        try {
            $states = $this->getStateData($board);
            return ResponseHelper::json($response, $states);
        } catch (\Throwable $e) {
            $this->logger->error("{$this->componentName()}: erreur getState — {msg}", ['msg' => $e->getMessage()]);
            return ResponseHelper::json($response, ['error' => 'Erreur serveur'], 500);
        }
    }

    /**
     * Auth optionnelle du GET d'état firmware (défaut DÉSACTIVÉE). Si
     * `FIRMWARE_STATE_REQUIRE_KEY` est vrai, exige un `X-Api-Key` (ou `?api_key=`)
     * valide — sans quoi ce GET public écrit en base (ack one-shot 110,
     * `updateLastRequest`) et un tiers pouvait consommer une commande de reset.
     * Le firmware envoie déjà l'en-tête ; laisser à `false` tant que toute la
     * flotte n'a pas été flashée avec cette version.
     */
    private function enforceFirmwareStateKey(Request $request, Response $response): ?Response
    {
        if (!($this->operationalSettings?->bool('FIRMWARE_STATE_REQUIRE_KEY', false)
            ?? filter_var($_ENV['FIRMWARE_STATE_REQUIRE_KEY'] ?? 'false', FILTER_VALIDATE_BOOLEAN))) {
            return null;
        }

        $expected = $_ENV['API_KEY'] ?? '';
        if (!is_string($expected) || $expected === '') {
            return ResponseHelper::json($response, ['error' => 'Configuration serveur manquante'], 500);
        }

        $provided = $request->getHeaderLine('X-Api-Key');
        if ($provided === '') {
            $qp = $request->getQueryParams();
            $provided = isset($qp['api_key']) ? trim((string) $qp['api_key']) : '';
        }

        if (!hash_equals($expected, $provided)) {
            $this->logger->warning("{$this->componentName()}: rejet getState cle API code=401", [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'n/a',
            ]);
            return ResponseHelper::json($response, ['error' => 'Cle API invalide'], 401);
        }

        return null;
    }

    /**
     * Vérifie si la requête est authentifiée (session ou token).
     * Utilisé pour protéger les actions de contrôle (toggle, setOutput, parameters).
     */
    protected function requireAuth(Request $request, Response $response): ?Response
    {
        $isAuth = $this->authService->isAuthenticated()
            || $this->authService->isAuthenticatedByToken($request->getQueryParams());
        if ($isAuth) {
            return null;
        }
        return ResponseHelper::json($response, ['error' => 'Authentification requise'], 401);
    }

    /**
     * POST API : bascule un output.
     */
    public function toggleOutput(Request $request, Response $response): Response
    {
        $authError = $this->requireAuth($request, $response);
        if ($authError !== null) {
            return $authError;
        }
        $params = RequestHelper::extractParams($request);
        $board = (int) ($params['board'] ?? $this->defaultBoard());

        $gpio = (int) ($params['gpio'] ?? 0);
        $name = trim((string) ($params['name'] ?? ''));
        if ($gpio > 0 && !$this->isGpioAllowed($gpio)) {
            $this->auditLogger->logAction(
                $request,
                $this->auditModule(),
                'toggle',
                ['gpio' => $gpio, 'name' => $name, 'board' => $board],
                false,
                'GPIO non autorisé'
            );
            return ResponseHelper::json($response, ['error' => 'GPIO non autorisé'], 400);
        }

        try {
            $result = $this->doToggle($params, $board);
            if (isset($result['success']) && $result['success'] === true) {
                $this->logger->info("{$this->componentName()}: toggle ok", $result);
                $this->auditLogger->logAction(
                    $request,
                    $this->auditModule(),
                    'toggle',
                    ['gpio' => $gpio, 'name' => $name, 'state' => $result['state'] ?? null, 'board' => $board],
                    true
                );
                return ResponseHelper::json($response, $result);
            }
            $status = $result['status'] ?? 400;
            unset($result['status']);
            $this->auditLogger->logAction(
                $request,
                $this->auditModule(),
                'toggle',
                ['gpio' => $gpio, 'name' => $name, 'board' => $board],
                false,
                is_string($result['error'] ?? null) ? $result['error'] : 'Échec toggle'
            );
            return ResponseHelper::json($response, $result, $status);
        } catch (\Throwable $e) {
            $this->logger->error("{$this->componentName()}: erreur toggle — {msg}", ['msg' => $e->getMessage()]);
            $this->auditLogger->logAction(
                $request,
                $this->auditModule(),
                'toggle',
                ['gpio' => $gpio, 'name' => $name, 'board' => $board],
                false,
                'Erreur serveur'
            );
            return ResponseHelper::json($response, ['error' => 'Erreur serveur'], 500);
        }
    }

    /** @return array<string, string> */
    protected function getDefaultParams(): array
    {
        $keys = $this->getDefaultParamKeys();
        $params = [];
        foreach ($keys as $key) {
            $params[$key] = $key === 'mail' ? '' : ($key === 'mailNotif' ? 'false' : '0');
        }
        // Veille infinie sous seuil batterie : active par defaut (comportement
        // historique du firmware). GPIO virtuel 112. Commun MSP/N3pp.
        if (in_array('veilleInfinie', $keys, true)) {
            $params['veilleInfinie'] = '1';
        }
        return $params;
    }

    /**
     * API: Met a jour un parametre.
     */
    public function updateParameters(Request $request, Response $response): Response
    {
        $authError = $this->requireAuth($request, $response);
        if ($authError !== null) {
            return $authError;
        }
        $payload = RequestHelper::extractParams($request);
        if (isset($payload['param'])) {
            $payload = [$payload['param'] => $payload['value'] ?? null];
        }
        if (!is_array($payload) || $payload === []) {
            return ResponseHelper::json($response, ['error' => 'Paramètre manquant'], 400);
        }

        $board = $this->defaultBoard();
        $paramName = (string) array_key_first($payload);
        $value = trim((string) ($payload[$paramName] ?? ''));

        // Validation stricte : le paramètre doit appartenir à l'ensemble canonique du module.
        if (!in_array($paramName, $this->getDefaultParamKeys(), true)) {
            $this->auditLogger->logAction(
                $request,
                $this->auditModule(),
                'update_parameter',
                ['param' => $paramName, 'board' => $board],
                false,
                'Paramètre non autorisé'
            );
            return ResponseHelper::json($response, ['error' => 'Paramètre inconnu'], 400);
        }

        $normalized = $this->normalizeAndValidateParameterValue($paramName, $value);
        if (($normalized['ok'] ?? false) !== true) {
            $this->auditLogger->logAction(
                $request,
                $this->auditModule(),
                'update_parameter',
                ['param' => $paramName, 'board' => $board],
                false,
                is_string($normalized['error'] ?? null) ? $normalized['error'] : 'Valeur invalide'
            );
            return ResponseHelper::json($response, ['error' => $normalized['error'] ?? 'Valeur invalide'], 400);
        }
        $value = (string) ($normalized['value'] ?? $value);

        try {
            $ok = $this->updateParameterByName($board, $paramName, $value);
            if (!$ok) {
                $this->auditLogger->logAction(
                    $request,
                    $this->auditModule(),
                    'update_parameter',
                    ['param' => $paramName, 'board' => $board],
                    false,
                    'Paramètre inconnu'
                );
                return ResponseHelper::json($response, ['error' => 'Paramètre inconnu'], 400);
            }
            $this->logger->info("{$this->componentName()}: parametre mis a jour", ['param' => $paramName]);
            $this->auditLogger->logAction(
                $request,
                $this->auditModule(),
                'update_parameter',
                ['param' => $paramName, 'value' => $value, 'board' => $board],
                true
            );
            return ResponseHelper::json($response, ['success' => true, 'param' => $paramName]);
        } catch (\Throwable $e) {
            $this->logger->error("{$this->componentName()}: erreur updateParameterByName", ['error' => $e->getMessage()]);
            $this->auditLogger->logAction(
                $request,
                $this->auditModule(),
                'update_parameter',
                ['param' => $paramName, 'board' => $board],
                false,
                'Erreur serveur'
            );
            return ResponseHelper::json($response, ['error' => 'Erreur serveur'], 500);
        }
    }

    /**
     * POST legacy /{module}/{module}control/{module}-outputs-action.php
     */
    public function setOutput(Request $request, Response $response): Response
    {
        $authError = $this->requireAuth($request, $response);
        if ($authError !== null) {
            return $authError;
        }
        $params = $request->getMethod() === 'POST' ? $request->getParsedBody() ?? [] : $request->getQueryParams();
        $action = $params['action'] ?? '';

        if ($action === 'output_create') {
            return $this->handleOutputCreate($request, $response);
        }
        if ($action !== 'set') {
            return ResponseHelper::json($response, ['error' => 'Action inconnue'], 400);
        }

        $board = (int) ($params['board'] ?? $this->defaultBoard());

        $gpio = (int) ($params['gpio'] ?? 0);
        $name = trim((string) ($params['name'] ?? ''));
        if ($gpio > 0 && !$this->isGpioAllowed($gpio)) {
            $this->auditLogger->logAction(
                $request,
                $this->auditModule(),
                'set',
                ['gpio' => $gpio, 'name' => $name, 'board' => $board],
                false,
                'GPIO non autorisé'
            );
            return ResponseHelper::json($response, ['error' => 'GPIO non autorisé'], 400);
        }

        try {
            $result = $this->doSetOutput($params, $board);
            if (($result['success'] ?? false) === true) {
                $this->logger->info("{$this->componentName()}: output mis a jour", $result + ['board' => $board]);
                $this->auditLogger->logAction(
                    $request,
                    $this->auditModule(),
                    'set',
                    ['gpio' => $gpio, 'name' => $name, 'state' => $result['state'] ?? null, 'board' => $board],
                    true
                );
                return ResponseHelper::json($response, $result);
            }
            $status = (int) ($result['status'] ?? 400);
            unset($result['status']);
            $this->auditLogger->logAction(
                $request,
                $this->auditModule(),
                'set',
                ['gpio' => $gpio, 'name' => $name, 'board' => $board],
                false,
                is_string($result['error'] ?? null) ? $result['error'] : 'Échec set'
            );
            return ResponseHelper::json($response, $result, $status);
        } catch (\Throwable $e) {
            $this->logger->error("{$this->componentName()}: erreur mise a jour", ['error' => $e->getMessage()]);
            $this->auditLogger->logAction(
                $request,
                $this->auditModule(),
                'set',
                ['gpio' => $gpio, 'name' => $name, 'board' => $board],
                false,
                'Erreur serveur'
            );
            return ResponseHelper::json($response, ['error' => 'Erreur serveur'], 500);
        }
    }

    private function handleOutputCreate(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];
        $board = $this->defaultBoard();
        $params = $this->getDefaultParams();
        foreach ($this->getDefaultParamKeys() as $key) {
            if (isset($body[$key])) {
                $rawValue = trim((string) $body[$key]);
                $normalized = $this->normalizeAndValidateParameterValue($key, $rawValue);
                if (($normalized['ok'] ?? false) !== true) {
                    return ResponseHelper::json($response, ['error' => $normalized['error'] ?? 'Valeur invalide'], 400);
                }
                $params[$key] = (string) ($normalized['value'] ?? $rawValue);
            }
        }

        try {
            $this->batchUpdateParameters($board, $params);
            $this->logger->info("{$this->componentName()}: parametres mis a jour (output_create)", ['board' => $board]);
            return ResponseHelper::json($response, ['success' => true]);
        } catch (\Throwable $e) {
            $this->logger->error("{$this->componentName()}: erreur batchUpdateParameters", ['error' => $e->getMessage()]);
            return ResponseHelper::json($response, ['error' => 'Erreur serveur'], 500);
        }
    }

    /**
     * @return array{ok: bool, value?: string, error?: string}
     */
    private function normalizeAndValidateParameterValue(string $paramName, string $value): array
    {
        if ($paramName === 'mailNotif') {
            return ['ok' => true, 'value' => in_array(strtolower($value), ['1', 'true', 'checked', 'on', 'oui'], true) ? 'checked' : 'false'];
        }

        if ($paramName === 'WakeUp' || $paramName === 'ServoModeAuto' || $paramName === 'veilleInfinie') {
            return ['ok' => true, 'value' => in_array(strtolower($value), ['1', 'true', 'on', 'oui'], true) ? '1' : '0'];
        }

        if ($paramName === 'ServoGD') {
            if (!is_numeric($value)) {
                return ['ok' => false, 'error' => 'ServoGD doit être un nombre entre 1 et 179'];
            }
            $servoGd = (int) round((float) $value);
            if ($servoGd < 1 || $servoGd > 179) {
                return ['ok' => false, 'error' => 'ServoGD hors limites (1-179)'];
            }
            return ['ok' => true, 'value' => (string) $servoGd];
        }

        if ($paramName === 'ServoHB') {
            if (!is_numeric($value)) {
                return ['ok' => false, 'error' => 'ServoHB doit être un nombre entre 40 et 145'];
            }
            $servoHb = (int) round((float) $value);
            if ($servoHb < 40 || $servoHb > 145) {
                return ['ok' => false, 'error' => 'ServoHB hors limites (40-145)'];
            }
            return ['ok' => true, 'value' => (string) $servoHb];
        }

        if ($paramName === 'SeuilSec') {
            if (!is_numeric($value)) {
                return ['ok' => false, 'error' => 'SeuilSec doit être un nombre entre 0 et 4095'];
            }
            $seuil = (int) round((float) $value);
            if ($seuil < 0 || $seuil > 4095) {
                return ['ok' => false, 'error' => 'SeuilSec hors limites (0-4095)'];
            }
            return ['ok' => true, 'value' => (string) $seuil];
        }

        if ($paramName === 'HeureArrosage') {
            if (!is_numeric($value)) {
                return ['ok' => false, 'error' => 'HeureArrosage doit être un nombre entre 0 et 23'];
            }
            $heure = (int) round((float) $value);
            if ($heure < 0 || $heure > 23) {
                return ['ok' => false, 'error' => 'HeureArrosage hors limites (0-23)'];
            }
            return ['ok' => true, 'value' => (string) $heure];
        }

        if ($paramName === 'tempsArrosage') {
            if (!is_numeric($value)) {
                return ['ok' => false, 'error' => 'tempsArrosage doit être un nombre entre 1 et 20'];
            }
            $duree = (int) round((float) $value);
            if ($duree < 1 || $duree > 20) {
                return ['ok' => false, 'error' => 'tempsArrosage hors limites (1-20 s)'];
            }
            return ['ok' => true, 'value' => (string) $duree];
        }

        if ($paramName === 'FreqWakeUp') {
            if (!is_numeric($value)) {
                return ['ok' => false, 'error' => 'FreqWakeUp doit être un nombre entre 1 et 86400'];
            }
            $freq = (int) round((float) $value);
            if ($freq < 1 || $freq > 86400) {
                return ['ok' => false, 'error' => 'FreqWakeUp hors limites (1-86400 s)'];
            }
            return ['ok' => true, 'value' => (string) $freq];
        }

        return ['ok' => true, 'value' => $value];
    }

    protected function requireAuthForNotificationPolicy(Request $request, Response $response): ?Response
    {
        return $this->requireAuth($request, $response);
    }
}

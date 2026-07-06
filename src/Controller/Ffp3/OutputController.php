<?php

declare(strict_types=1);

namespace App\Controller\Ffp3;

use App\Config\Database;
use App\Config\TableConfig;
use App\Config\Version;
use App\Controller\Traits\HandlesNotificationPolicy;
use App\Notification\NotificationFamily;
use App\Repository\NotificationPolicyRepository;
use App\Repository\OutputRepository;
use App\Repository\SensorReadRepository;
use App\Service\ControlAuditLogger;
use App\Service\LogService;
use App\Service\NotificationPolicySaveService;
use App\Service\NotificationService;
use App\Service\OutputCacheService;
use App\Service\OutputService;
use App\Service\TemplateRenderer;
use App\Util\RealtimeUrlHelper;
use App\Util\RequestHelper;
use App\Util\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Contrôleur pour l'interface de contrôle des GPIO/outputs FFP3 (aquaponie)
 *
 * Gère l'affichage et les actions (toggle, update) sur les outputs
 */
class OutputController
{
    use HandlesNotificationPolicy;

    private const AQUARIUM_PUMP_GPIO = 16;
    private const AQUARIUM_PUMP_FORCE_GPIO = 117;

    public function __construct(
        private OutputService $outputService,
        private TemplateRenderer $renderer,
        private SensorReadRepository $sensorReadRepo,
        private OutputCacheService $outputCache,
        private LogService $logger,
        private ControlAuditLogger $auditLogger,
        private NotificationPolicyRepository $notificationPolicyRepo,
        private NotificationPolicySaveService $notificationSaveService,
        private NotificationService $notificationService,
    ) {
    }

    /**
     * Affiche l'interface de contrôle
     */
    public function showInterface(Request $request, Response $response): Response
    {
        try {
            $outputs = $this->outputService->getAllOutputs();
            $boards = $this->outputService->getActiveBoardsForCurrentEnvironment();
            $params = $this->outputService->getParametersMap();

            foreach ($boards as &$board) {
                try {
                    $board['last_gpio'] = $this->outputService->getLastModifiedGpio((string) $board['board']);
                } catch (\Throwable $e) {
                    $board['last_gpio'] = null;
                }
            }

            $environment = TableConfig::getEnvironment();
            $firmwareVersion = $this->sensorReadRepo->getFirmwareVersion();

            $parameterGpioMap = OutputRepository::getParameterGpioMap();

            $lastData = $this->outputService->getLastDataStates();

            $realtime_api_base = RealtimeUrlHelper::getRealtimeApiBase($environment);
            $outputs_api_base = RealtimeUrlHelper::getOutputsApiBase($environment);

            $this->outputService->ensureAquariumPumpForceOutputRow();
            $this->notificationPolicyRepo->ensurePolicyRows(NotificationFamily::Ffp3);

            $data = array_merge([
                'outputs' => $outputs,
                'boards' => $boards,
                'params' => $params,
                'parameter_gpio_map' => $parameterGpioMap,
                'title' => 'Contrôle du ffp3',
                'nav_active' => 'control_ffp3',
                'environment' => $environment,
                'realtime_api_base' => $realtime_api_base,
                'outputs_api_base' => $outputs_api_base,
                'version' => Version::getWithPrefix(),
                'firmware_version' => $firmwareVersion,
                'lastDataStates' => $lastData['states'],
                'lastDataReadingTime' => $lastData['readingTime'],
            ], $this->notificationPolicyTwigData($this->notificationPolicyRepo));

            $html = $this->renderer->render('control.twig', $data);
            $response->getBody()->write($html);

            return $response;

        } catch (\Throwable $e) {
            $errorId = substr(bin2hex(random_bytes(8)), 0, 12);
            $this->logger->error(
                '[n3 500] [{error_id}] GET (aquaponie-control) OutputController::showInterface — {class}: {msg} in {file}:{line}',
                [
                    'error_id' => $errorId,
                    'class' => $e::class,
                    'msg' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => (string) $e->getLine(),
                ]
            );
            $this->logger->error('Trace: ' . $e->getTraceAsString());

            $isDevelopment = in_array($_ENV['ENV'] ?? 'prod', ['test', 'test3'], true) || (bool) ($_ENV['DEBUG'] ?? false);

            if ($isDevelopment) {
                $errorMessage = sprintf(
                    "ERREUR OutputController: %s\nFichier: %s\nLigne: %d\n\nStack trace:\n%s",
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine(),
                    $e->getTraceAsString()
                );
            } else {
                $errorMessage = "Une erreur serveur est survenue. Veuillez contacter l'administrateur.\n\nRéférence : " . $errorId . ' (à indiquer en cas de signalement.)';
            }

            return ResponseHelper::text($response, $errorMessage, 500);
        }
    }

    /**
     * API: Toggle un output (change son état)
     * L'environnement est défini par EnvironmentMiddleware sur le groupe de routes.
     */
    public function toggleOutput(Request $request, Response $response): Response
    {
        return $this->handleToggle($request, $response);
    }

    /**
     * API : nourrissage manuel (GPIO 108/109) — contrat « compteur monotone ».
     *
     * Corps JSON : { "id": int, "gpio": 108|109 }
     * Un appel = +1 sur le compteur de la sortie. Cliquer N fois = nourrir N fois.
     * Le firmware rattrape l'écart (un repas par poll, plafonné). Plus de séquence
     * reset→trigger ni de front 0→1.
     *
     * Rétrocompatibilité : un client encore en cache peut envoyer `step:"reset"` puis
     * `step:"trigger"`. On ignore l'étape `reset` (no-op) pour ne pas compter deux fois ;
     * `trigger` ou l'absence d'étape déclenche l'unique incrément.
     */
    public function triggerManualFeed(Request $request, Response $response): Response
    {
        $params = RequestHelper::extractParams($request);
        $id = RequestHelper::getInt($params, 'id', 0);
        $gpio = RequestHelper::getInt($params, 'gpio', 0);
        $step = isset($params['step']) && is_string($params['step']) ? trim($params['step']) : '';

        if ($id === 0 || !$this->outputService->isManualFeedGpio($gpio)) {
            $this->auditLogger->logAction(
                $request,
                'ffp3',
                'trigger_manual_feed',
                ['id' => $id, 'gpio' => $gpio, 'step' => $step],
                false,
                'Invalid parameters'
            );

            return ResponseHelper::error($response, 'Invalid parameters', 400);
        }

        // Étape legacy « reset » : no-op (le compteur monotone n'est jamais remis à zéro).
        if ($step === 'reset') {
            return ResponseHelper::success($response, ['success' => true, 'gpio' => $gpio, 'step' => 'reset', 'noop' => true]);
        }

        $result = $this->outputService->triggerManualFeed($id, $gpio);

        if (!$result['success']) {
            $this->auditLogger->logAction(
                $request,
                'ffp3',
                'trigger_manual_feed',
                ['id' => $id, 'gpio' => $gpio],
                false,
                $result['error'] ?? 'Échec persistance'
            );

            return ResponseHelper::error($response, $result['error'] ?? 'Failed to trigger feed', 500);
        }

        $this->auditLogger->logAction($request, 'ffp3', 'trigger_manual_feed', [
            'id' => $id,
            'gpio' => $gpio,
            'counter' => $result['counter'],
            'feed_cmd_id' => $result['feed_cmd_id'],
        ], true);

        return ResponseHelper::success($response, $result);
    }

    private function handleToggle(Request $request, Response $response): Response
    {
        $params = RequestHelper::extractParams($request);

        $id = RequestHelper::getInt($params, 'id', 0);
        $state = RequestHelper::getInt($params, 'state', -1);
        $gpio = RequestHelper::getInt($params, 'gpio', 0);

        // GPIO 117 (forçage pompe aquarium) : sélecteur tri-état 0=auto / 1=forcer ON / 2=forcer OFF,
        // persisté par GPIO (id HTML non requis). Les autres GPIO restent booléens (0/1) + id requis.
        $isPumpForce = ($gpio === self::AQUARIUM_PUMP_FORCE_GPIO);
        $stateValid = $isPumpForce
            ? in_array($state, [0, 1, 2], true)
            : ($state === 0 || $state === 1);
        $idValid = $isPumpForce || $id !== 0;

        if (!$idValid || !$stateValid) {
            $this->auditLogger->logAction(
                $request,
                'ffp3',
                'toggle',
                ['id' => $id, 'gpio' => $gpio, 'state' => $state],
                false,
                'Invalid parameters'
            );
            return ResponseHelper::error($response, 'Invalid parameters', 400);
        }

        // Validation stricte : un GPIO ciblé doit appartenir à l'ensemble canonique autorisé.
        if ($gpio !== 0 && !$this->outputService->isGpioAllowed($gpio)) {
            $this->auditLogger->logAction(
                $request,
                'ffp3',
                'toggle',
                ['id' => $id, 'gpio' => $gpio, 'state' => $state],
                false,
                'GPIO non autorisé'
            );
            return ResponseHelper::error($response, 'Unauthorized GPIO', 400);
        }

        // Toggle de la pompe (GPIO 16) en conflit avec un mode de forçage actif (GPIO 117) :
        // on ne ment plus au client (faux « Commande envoyée »). On maintient l'état BDD
        // cohérent avec le forçage et on signale le blocage pour inviter à repasser en Auto.
        $blockMessage = null;
        if ($gpio === self::AQUARIUM_PUMP_GPIO) {
            $forceMode = $this->outputService->getAquariumPumpForceMode();
            if ($forceMode === 1 && $state === 0) {
                $state = 1;
                $blockMessage = 'Arrêt ignoré : le mode « forcer pompe aquarium ON » (GPIO 117) est actif. '
                    . "Repassez la pompe en mode Auto pour pouvoir l'arrêter.";
            } elseif ($forceMode === 2 && $state === 1) {
                $state = 0;
                $blockMessage = 'Démarrage ignoré : le mode « forcer pompe aquarium OFF » (GPIO 117) est actif. '
                    . 'Repassez la pompe en mode Auto pour pouvoir la démarrer.';
            }
        }
        $blockedByForce = $blockMessage !== null;

        // GPIO 117 : persistance par numéro GPIO (toutes les lignes) plutôt que par id, pour
        // éviter un faux succès si l'id HTML est obsolète. Tri-état 0/1/2 via updatePumpForceMode.
        if ($isPumpForce) {
            $success = $this->outputService->updatePumpForceMode($state);
        } else {
            $success = $this->outputService->updateStateById($id, $state, 'web');
        }

        if ($success) {
            if ($isPumpForce) {
                // Reflet immédiat sur GPIO 16 selon le mode ; Auto (0) laisse l'ESP32 reprendre la main.
                if ($state === 1) {
                    $this->outputService->updateStateByGpio(self::AQUARIUM_PUMP_GPIO, 1);
                } elseif ($state === 2) {
                    $this->outputService->updateStateByGpio(self::AQUARIUM_PUMP_GPIO, 0);
                }
            }
            $this->auditLogger->logAction(
                $request,
                'ffp3',
                'toggle',
                ['id' => $id, 'gpio' => $gpio, 'state' => $state, 'blocked_by_force' => $blockedByForce],
                true,
                $blockMessage
            );
            $payload = ['id' => $id, 'state' => $state];
            if ($blockedByForce) {
                $payload['blocked'] = true;
                $payload['message'] = $blockMessage;
            }
            return ResponseHelper::success($response, $payload);
        }

        $this->auditLogger->logAction(
            $request,
            'ffp3',
            'toggle',
            ['id' => $id, 'gpio' => $gpio, 'state' => $state],
            false,
            'Persistence failed'
        );
        return ResponseHelper::error($response, 'Failed to update output', 500);
    }

    /**
     * API: Met à jour plusieurs paramètres depuis un formulaire
     */
    public function updateParameters(Request $request, Response $response): Response
    {
        $payload = RequestHelper::extractParams($request);

        // Gestion du format {param: ..., value: ...}
        if (isset($payload['param'])) {
            $payload = [$payload['param'] => $payload['value'] ?? null];
        }

        if (!is_array($payload) || $payload === []) {
            return ResponseHelper::error($response, 'No parameters provided', 400);
        }

        // Validation stricte : tout paramètre doit appartenir à l'ensemble canonique autorisé.
        foreach (array_keys($payload) as $paramName) {
            if (!$this->outputService->isParameterAllowed((string) $paramName)) {
                $this->auditLogger->logAction(
                    $request,
                    'ffp3',
                    'update_parameter',
                    ['param' => (string) $paramName],
                    false,
                    'Paramètre non autorisé'
                );
                return ResponseHelper::error($response, 'Unauthorized parameter', 400);
            }
        }

        try {
            $result = $this->outputService->updateMultipleParameters($payload);

            foreach ($payload as $paramName => $value) {
                $this->auditLogger->logAction(
                    $request,
                    'ffp3',
                    'update_parameter',
                    ['param' => (string) $paramName, 'value' => is_scalar($value) ? $value : null],
                    true
                );
            }

            return ResponseHelper::success($response, [
                'updated' => $result['updated'],
                'warnings' => $result['warnings'],
            ]);
        } catch (\InvalidArgumentException $e) {
            $this->auditLogger->logAction(
                $request,
                'ffp3',
                'update_parameter',
                ['params' => implode(',', array_keys($payload))],
                false,
                $e->getMessage()
            );
            // Erreur de validation (ex. horaire de nourrissage hors de [0..23]) : 400, pas 500
            return ResponseHelper::error($response, $e->getMessage(), 400);
        } catch (\Throwable $e) {
            $errorId = substr(bin2hex(random_bytes(8)), 0, 12);
            $this->logger->error(
                '[n3 500] [{error_id}] POST (aquaponie-control) OutputController::updateParameters — {class}: {msg} in {file}:{line}',
                [
                    'error_id' => $errorId,
                    'class' => $e::class,
                    'msg' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => (string) $e->getLine(),
                ]
            );
            $this->logger->error('Trace: ' . $e->getTraceAsString());

            return ResponseHelper::error($response, 'Failed to persist parameters', 500);
        }
    }

    /**
     * API: Récupère l'état actuel de tous les outputs (pour ESP32)
     * Version 11.68: Format simplifié - GPIO numériques uniquement
     * Version 11.127: Cache ajouté pour réduire charge serveur
     */
    public function getOutputsState(Request $request, Response $response): Response
    {
        // Liste des GPIOs critiques attendus par l'ESP32 (voir include/gpio_mapping.h)
        $gpioList = [
            2, 15, 16, 18, // actionneurs physiques: chauffage, lumière, pompe aqua, pompe tank
            100, 101, 102, 103, 104, 105, 106, 107, // email + params
            108, 109, 110, // commandes nourrissage + reset
            111, 112, 113, 114, 115, 116, // durées / limites / wake
            118, 119, 120, 121, 122, 123, // angles servo nourrissage
            117, // forçage serveur pompe aquarium (page contrôle + sync JSON)
        ];

        // Page de contrôle : ?fresh=1 pour ignorer le cache et afficher les vraies valeurs BDD
        $queryParams = $request->getQueryParams();
        $skipCache = isset($queryParams['fresh']) && (string) $queryParams['fresh'] === '1';
        // Les polls de l'interface ne doivent pas consommer le one-shot OTA destiné au firmware.
        $consumeOtaTrigger = !$skipCache;

        $this->outputService->ensureAquariumPumpForceOutputRow();
        $this->outputService->ensureServoAngleRows();

        $pdo = Database::getConnection();
        $result = $this->outputCache->getOutputsState($pdo, $gpioList, $skipCache, $consumeOtaTrigger);

        // Témoins "dernier état Data" pour la page de contrôle (ESP32 ignore ces clés)
        $lastData = $this->outputService->getLastDataStates();
        $result['dataStates'] = [];
        foreach ($lastData['states'] as $gpio => $state) {
            $result['dataStates'][(string) $gpio] = $state;
        }
        $result['dataStatesReadingTime'] = $lastData['readingTime'];

        // v4.9.42: JSON compact (sans PRETTY_PRINT) pour rester sous 1024 bytes côté ESP32-WROOM
        // v4.9.43: Content-Length explicite pour éviter chunked + timeout lecture côté ESP32
        // JSON_INVALID_UTF8_SUBSTITUTE: évite JSON invalide si données BDD contiennent UTF-8 corrompu (InvalidInput ArduinoJson)
        $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            throw new \RuntimeException('getOutputsState: json_encode failed — ' . json_last_error_msg());
        }
        $response->getBody()->write($json);
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Content-Length', (string) strlen($json))
            ->withStatus(200);
    }

    /**
     * API: Récupère le statut d'une board spécifique (dernière requête + GPIO)
     */
    public function getBoardStatus(Request $request, Response $response): Response
    {
        $route = $request->getAttribute('route');
        if ($route === null) {
            return ResponseHelper::error($response, 'Route not found', 500);
        }

        $routeParams = $route->getArguments();
        $boardNumber = $routeParams['board'] ?? null;

        if (!$boardNumber) {
            return ResponseHelper::error($response, 'Board number required', 400);
        }

        try {
            $status = $this->outputService->getBoardStatus($boardNumber);

            if ($status === null) {
                return ResponseHelper::error($response, 'Board not found', 404);
            }

            return ResponseHelper::json($response, $status);

        } catch (\Throwable $e) {
            return ResponseHelper::error($response, 'Internal server error', 500);
        }
    }

    /**
     * POST: Demande de vérification OTA pour l'environnement courant.
     * Le prochain GET state firmware renverra triggerOtaCheck: true à l'ESP32 une fois.
     */
    public function triggerOtaCheck(Request $request, Response $response): Response
    {
        $this->outputCache->setTriggerOtaCheckRequested();
        $this->auditLogger->logAction($request, 'ffp3', 'trigger_ota_check', [], true);
        return ResponseHelper::json($response, [
            'ok' => true,
            'message' => "Demande envoyée. L'ESP32 vérifiera la mise à jour au prochain cycle.",
        ], 200);
    }

    protected function notificationFamily(): NotificationFamily
    {
        return NotificationFamily::Ffp3;
    }

    protected function notificationSaveService(): NotificationPolicySaveService
    {
        return $this->notificationSaveService;
    }

    protected function notificationService(): NotificationService
    {
        return $this->notificationService;
    }

    protected function notificationPolicyRepository(): NotificationPolicyRepository
    {
        return $this->notificationPolicyRepo;
    }

    public function saveNotificationPolicy(Request $request, Response $response): Response
    {
        return $this->updateNotificationPolicy($request, $response);
    }

    protected function requireAuthForNotificationPolicy(Request $request, Response $response): ?Response
    {
        return null;
    }
}

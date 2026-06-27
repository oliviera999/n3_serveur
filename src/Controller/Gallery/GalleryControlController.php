<?php

declare(strict_types=1);

namespace App\Controller\Gallery;

use App\Config\Version;
use App\Controller\Traits\HandlesNotificationPolicy;
use App\Notification\NotificationFamily;
use App\Repository\GalleryControlRepository;
use App\Repository\GallerySyncRepository;
use App\Repository\NotificationPolicyRepository;
use App\Security\AuthService;
use App\Security\DeviceApiKeyValidator;
use App\Service\LogService;
use App\Service\NotificationPolicySaveService;
use App\Service\TemplateRenderer;
use App\Util\RequestHelper;
use App\Util\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Controle distant des firmwares uploadphotosserver (msp1/n3pp/ffp3).
 */
class GalleryControlController
{
    use HandlesNotificationPolicy;

    private ?string $gallerySlugContext = null;

    public function __construct(
        private readonly GalleryControlRepository $repository,
        private readonly TemplateRenderer $renderer,
        private readonly AuthService $authService,
        private readonly LogService $logger,
        private readonly NotificationPolicyRepository $notificationPolicyRepo,
        private readonly GallerySyncRepository $syncRepository,
    ) {
    }

    public function showControlPage(Request $request, Response $response, array $args): Response
    {
        if (!$this->authService->isAuthenticated() && !$this->authService->isAuthenticatedByToken($request->getQueryParams())) {
            $redirect = '/login?redirect=' . urlencode($request->getUri()->getPath());
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $slug = (string) ($args['slug'] ?? '');
        if (!$this->isAllowedSlug($slug)) {
            return ResponseHelper::text($response, 'Galerie inconnue', 404);
        }

        try {
            $module = $this->repository->getModule($slug);
            $outputs = $this->repository->getOutputsForControlPage($slug);
            $params = $this->repository->getParametersForControlPage($slug);
            $data = array_merge([
                'page_title' => 'Controle galerie ' . $module['label'] . ' - n3 iot',
                'gallery_slug' => $slug,
                'gallery_label' => $module['label'],
                'board' => $module['board'],
                'outputs' => $outputs,
                'params' => $params,
                'last_board_request' => $this->repository->getLastBoardRequest($slug),
                'firmware_version' => $this->repository->getFirmwareVersion($slug) ?? '',
                'version' => Version::getWithPrefix(),
                'environment' => $_ENV['ENV'] ?? 'prod',
                'nav_active' => 'gallery_control',
                'outputs_api_base' => '/gallery/' . $slug . '/api/outputs',
                'sync_api_base' => '/gallery/' . $slug . '/api/sync',
                'sync_status' => $this->syncRepository->getStatus($slug),
                'control_config' => $this->buildControlConfig($slug, $module['label'], count($outputs)),
            ], $this->notificationPolicyTwigDataForSlug($slug));

            $html = $this->renderer->render('gallery_control.twig', $data);
            $response->getBody()->write($html);
            return $response;
        } catch (\Throwable $e) {
            $this->logger->error('GalleryControlController: erreur showControlPage — {msg}', ['msg' => $e->getMessage()]);
            return ResponseHelper::text($response, 'Erreur serveur', 500);
        }
    }

    public function getStateBySlug(Request $request, Response $response, array $args): Response
    {
        $slug = (string) ($args['slug'] ?? '');
        return $this->getStateInternal($request, $response, $slug);
    }

    public function getMsp1State(Request $request, Response $response): Response
    {
        return $this->getStateInternal($request, $response, 'msp1');
    }

    public function getN3ppState(Request $request, Response $response): Response
    {
        return $this->getStateInternal($request, $response, 'n3pp');
    }

    public function getFfp3State(Request $request, Response $response): Response
    {
        return $this->getStateInternal($request, $response, 'ffp3');
    }

    public function toggleOutputBySlug(Request $request, Response $response, array $args): Response
    {
        $slug = (string) ($args['slug'] ?? '');
        return $this->toggleOutputInternal($request, $response, $slug);
    }

    public function updateParametersBySlug(Request $request, Response $response, array $args): Response
    {
        $slug = (string) ($args['slug'] ?? '');
        return $this->updateParametersInternal($request, $response, $slug);
    }

    public function postMsp1FirmwareVersion(Request $request, Response $response): Response
    {
        return $this->postFirmwareVersionInternal($request, $response, 'msp1');
    }

    public function postFirmwareVersionBySlug(Request $request, Response $response, array $args): Response
    {
        $slug = (string) ($args['slug'] ?? '');
        return $this->postFirmwareVersionInternal($request, $response, $slug);
    }

    public function postN3ppFirmwareVersion(Request $request, Response $response): Response
    {
        return $this->postFirmwareVersionInternal($request, $response, 'n3pp');
    }

    public function postFfp3FirmwareVersion(Request $request, Response $response): Response
    {
        return $this->postFirmwareVersionInternal($request, $response, 'ffp3');
    }

    private function getStateInternal(Request $request, Response $response, string $slug): Response
    {
        if (!$this->isAllowedSlug($slug)) {
            return ResponseHelper::json($response, ['error' => 'Galerie inconnue'], 404);
        }

        $deviceAuthError = $this->requireDeviceApiKey($request, $response);
        if ($deviceAuthError !== null) {
            return $deviceAuthError;
        }

        $queryParams = $request->getQueryParams();
        $action = (string) ($queryParams['action'] ?? '');
        if ($action !== '' && $action !== 'outputs_state') {
            return ResponseHelper::json($response, ['error' => 'Action inconnue'], 400);
        }
        $module = $this->repository->getModule($slug);
        $boardValidationError = $this->validateBoardForSlug($response, $module['board'], $queryParams);
        if ($boardValidationError !== null) {
            return $boardValidationError;
        }

        try {
            $state = $this->repository->getStateForFirmware($slug);
            return ResponseHelper::json($response, $state);
        } catch (\Throwable $e) {
            $this->logger->error('GalleryControlController: erreur getState — {msg}', ['msg' => $e->getMessage(), 'slug' => $slug]);
            return ResponseHelper::json($response, ['error' => 'Erreur serveur'], 500);
        }
    }

    private function toggleOutputInternal(Request $request, Response $response, string $slug): Response
    {
        $authError = $this->requireAuth($request, $response);
        if ($authError !== null) {
            return $authError;
        }
        if (!$this->isAllowedSlug($slug)) {
            return ResponseHelper::json($response, ['error' => 'Galerie inconnue'], 404);
        }

        $params = array_merge($request->getQueryParams(), RequestHelper::extractParams($request));
        $gpio = (int) ($params['gpio'] ?? 0);
        $state = (int) ($params['state'] ?? -1);
        if ($gpio <= 0 || ($state !== 0 && $state !== 1)) {
            return ResponseHelper::json($response, ['error' => 'Parametres gpio/state invalides'], 400);
        }

        try {
            $this->repository->updateByGpio($slug, $gpio, (string) $state);
            return ResponseHelper::json($response, ['success' => true, 'gpio' => $gpio, 'state' => $state]);
        } catch (\Throwable $e) {
            $this->logger->error('GalleryControlController: erreur toggle — {msg}', ['msg' => $e->getMessage(), 'slug' => $slug]);
            return ResponseHelper::json($response, ['error' => 'Erreur serveur'], 500);
        }
    }

    private function updateParametersInternal(Request $request, Response $response, string $slug): Response
    {
        $authError = $this->requireAuth($request, $response);
        if ($authError !== null) {
            return $authError;
        }
        if (!$this->isAllowedSlug($slug)) {
            return ResponseHelper::json($response, ['error' => 'Galerie inconnue'], 404);
        }

        $payload = RequestHelper::extractParams($request);
        if (!isset($payload['param'])) {
            return ResponseHelper::json($response, ['error' => 'Parametre manquant'], 400);
        }

        $paramName = trim((string) $payload['param']);
        $value = trim((string) ($payload['value'] ?? ''));
        if ($paramName === 'mailNotif') {
            $value = in_array(strtolower($value), ['1', 'true', 'checked', 'on', 'oui'], true) ? 'checked' : 'false';
        } elseif ($paramName === 'forceWakeUp' || $paramName === 'resetMode') {
            $value = in_array($value, ['1', 'true', 'on'], true) ? '1' : '0';
        } elseif ($paramName === 'sleepTime') {
            $sleep = (int) $value;
            if ($sleep < 10) {
                $sleep = 10;
            }
            if ($sleep > 86400) {
                $sleep = 86400;
            }
            $value = (string) $sleep;
        }

        try {
            $ok = $this->repository->updateParameterByName($slug, $paramName, $value);
            if (!$ok) {
                return ResponseHelper::json($response, ['error' => 'Parametre inconnu'], 400);
            }
            return ResponseHelper::json($response, ['success' => true, 'param' => $paramName, 'value' => $value]);
        } catch (\Throwable $e) {
            $this->logger->error('GalleryControlController: erreur updateParameters — {msg}', ['msg' => $e->getMessage(), 'slug' => $slug]);
            return ResponseHelper::json($response, ['error' => 'Erreur serveur'], 500);
        }
    }

    private function postFirmwareVersionInternal(Request $request, Response $response, string $slug): Response
    {
        if (!$this->isAllowedSlug($slug)) {
            return ResponseHelper::json($response, ['error' => 'Galerie inconnue'], 404);
        }

        $deviceAuthError = $this->requireDeviceApiKey($request, $response);
        if ($deviceAuthError !== null) {
            return $deviceAuthError;
        }

        $params = RequestHelper::extractParams($request);
        $version = trim((string) ($params['version'] ?? ($params['firmware_version'] ?? '')));
        if ($version === '') {
            return ResponseHelper::json($response, ['error' => 'Version manquante'], 400);
        }
        if (strlen($version) > 64) {
            $version = substr($version, 0, 64);
        }

        $module = $this->repository->getModule($slug);
        $boardValidationError = $this->validateBoardForSlug($response, $module['board'], $params);
        if ($boardValidationError !== null) {
            return $boardValidationError;
        }
        $sensorValidationError = $this->validateSensorForSlug($response, $slug, $params);
        if ($sensorValidationError !== null) {
            return $sensorValidationError;
        }

        try {
            $this->repository->updateFirmwareVersion($slug, $version);
            return ResponseHelper::json($response, ['success' => true, 'version' => $version]);
        } catch (\Throwable $e) {
            $this->logger->error('GalleryControlController: erreur postFirmwareVersion — {msg}', ['msg' => $e->getMessage(), 'slug' => $slug]);
            return ResponseHelper::json($response, ['error' => 'Erreur serveur'], 500);
        }
    }

    private function isAllowedSlug(string $slug): bool
    {
        return in_array($slug, ['msp1', 'n3pp', 'ffp3'], true);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function validateBoardForSlug(Response $response, int $expectedBoard, array $params): ?Response
    {
        if (!array_key_exists('board', $params)) {
            return null;
        }
        $boardRaw = $params['board'];
        if (!is_scalar($boardRaw) || !is_numeric((string) $boardRaw)) {
            return ResponseHelper::json($response, ['error' => 'Parametre board invalide'], 400);
        }
        $board = (int) $boardRaw;
        if ($board !== $expectedBoard) {
            return ResponseHelper::json($response, ['error' => 'Board incompatible avec la galerie cible'], 400);
        }
        return null;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function validateSensorForSlug(Response $response, string $slug, array $params): ?Response
    {
        if (!array_key_exists('sensor', $params)) {
            return null;
        }
        $sensorRaw = $params['sensor'];
        if (!is_scalar($sensorRaw)) {
            return ResponseHelper::json($response, ['error' => 'Parametre sensor invalide'], 400);
        }
        $sensor = strtolower(trim((string) $sensorRaw));
        if ($sensor === '' || $sensor === 'cam' || $sensor === $slug) {
            return null;
        }
        return ResponseHelper::json($response, ['error' => 'Sensor incompatible avec la galerie cible'], 400);
    }

    private function requireAuth(Request $request, Response $response): ?Response
    {
        $isAuth = $this->authService->isAuthenticated()
            || $this->authService->isAuthenticatedByToken($request->getQueryParams());
        if ($isAuth) {
            return null;
        }

        return ResponseHelper::json($response, ['error' => 'Authentification requise'], 401);
    }

    /**
     * Auth firmware (poll état / version) : X-Api-Key ou api_key en query/body.
     */
    private function requireDeviceApiKey(Request $request, Response $response): ?Response
    {
        $params = array_merge($request->getQueryParams(), RequestHelper::extractParams($request));
        if (DeviceApiKeyValidator::isValidRequest($request, $params)) {
            return null;
        }

        $this->logger->warning('GalleryControl: rejet auth device api_key', [
            'path' => $request->getUri()->getPath(),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'n/a',
        ]);

        return ResponseHelper::json($response, ['error' => 'Cle API invalide'], 401);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildControlConfig(string $slug, string $label, int $outputsCount): array
    {
        $descriptions = [
            'msp1' => 'Controle distant de la camera MSP1 (galerie meteo). Les commandes sont appliquees au prochain reveil.',
            'n3pp' => 'Controle distant de la camera N3PP (galerie serre). Les commandes sont appliquees au prochain reveil.',
            'ffp3' => 'Controle distant de la camera FFP3 (galerie aquaponie). Les commandes sont appliquees au prochain reveil.',
        ];

        return [
            'test_env' => 'prod',
            'sidebar_title' => 'Galerie ' . $label,
            'sidebar_description' => $descriptions[$slug] ?? 'Controle distant camera.',
            'outputs_count' => $outputsCount,
            'icon' => 'fa-camera',
            'main_title' => 'Controle camera ' . $label,
            'main_description' => 'Pilotez les parametres de reveil et de notification de la camera uploadphotosserver.',
            'default_api_base' => '/gallery/' . $slug . '/api/outputs',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function notificationPolicyTwigDataForSlug(string $slug): array
    {
        $this->gallerySlugContext = $slug;
        $family = $this->notificationFamily();
        $this->notificationPolicyRepo->ensurePolicyRows($family);

        return $this->notificationPolicyTwigData($this->notificationPolicyRepo);
    }

    protected function notificationFamily(): NotificationFamily
    {
        $slug = $this->gallerySlugContext ?? 'ffp3';

        return NotificationFamily::fromGallerySlug($slug) ?? NotificationFamily::GalleryFfp3;
    }

    public function saveNotificationPolicyBySlug(
        Request $request,
        Response $response,
        array $args,
        NotificationPolicySaveService $saveService
    ): Response {
        $slug = (string) ($args['slug'] ?? '');
        if (!$this->isAllowedSlug($slug)) {
            return ResponseHelper::json($response, ['error' => 'Galerie inconnue'], 404);
        }
        $this->gallerySlugContext = $slug;

        return $this->updateNotificationPolicy(
            $request,
            $response,
            $saveService,
            $this->notificationPolicyRepo
        );
    }

    protected function requireAuthForNotificationPolicy(Request $request, Response $response): ?Response
    {
        return $this->requireAuth($request, $response);
    }
}

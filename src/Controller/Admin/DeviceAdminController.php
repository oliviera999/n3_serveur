<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\BoardRepository;
use App\Repository\IotDeviceRepository;
use App\Repository\NavPageRepository;
use App\Security\AuthService;
use App\Service\LogService;
use App\Util\RequestHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * CRUD admin du catalogue d'appareils (instances de familles connues).
 */
final class DeviceAdminController
{
    /** Defaults de pages / live par famille (prod). */
    private const FAMILY_DEFAULTS = [
        'ffp3' => [
            'page_key' => 'aquaponie',
            'page_url' => '/aquaponie',
            'live_module_id' => null,
            'live_health_url' => '/api/realtime/system/health',
            'env' => 'prod',
        ],
        'msp1' => [
            'page_key' => 'potager',
            'page_url' => '/meteo',
            'live_module_id' => null,
            'live_health_url' => '/msp1/api/realtime/system/health',
            'env' => 'prod',
        ],
        'n3pp' => [
            'page_key' => 'elevage',
            'page_url' => '/serre',
            'live_module_id' => null,
            'live_health_url' => '/n3pp/api/realtime/system/health',
            'env' => 'prod',
        ],
        'energie' => [
            'page_key' => 'energie',
            'page_url' => '/energie',
            'live_module_id' => null,
            'live_health_url' => '/energie/api/realtime/system/health',
            'env' => 'prod',
        ],
        'pgl' => [
            'page_key' => 'pgl',
            'page_url' => '/pgl',
            'live_module_id' => null,
            'live_health_url' => null,
            'env' => 'prod',
        ],
        'gallery' => [
            'page_key' => 'gallery',
            'page_url' => '/gallery',
            'live_module_id' => null,
            'live_health_url' => null,
            'env' => 'prod',
        ],
    ];

    public function __construct(
        private IotDeviceRepository $deviceRepository,
        private NavPageRepository $navPageRepository,
        private BoardRepository $boardRepository,
        private AuthService $authService,
        private LogService $logger,
    ) {
    }

    public function list(Request $request, Response $response): Response
    {
        return $this->json($response, [
            'success' => true,
            'families' => IotDeviceRepository::FAMILIES,
            'devices' => $this->deviceRepository->findAll(false),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        if (!$this->authService->isAdmin()) {
            return $this->json($response, [
                'success' => false,
                'error' => 'Action réservée aux administrateurs.',
            ], 403);
        }

        $params = RequestHelper::extractParams($request);
        $deviceId = strtolower(trim((string) ($params['device_id'] ?? '')));
        $family = strtolower(trim((string) ($params['family'] ?? '')));
        $label = trim((string) ($params['label'] ?? ''));
        $board = trim((string) ($params['board'] ?? ''));
        $env = trim((string) ($params['env'] ?? ''));
        $showInNav = $this->toBool($params['show_in_nav'] ?? true);
        $notes = trim((string) ($params['notes'] ?? ''));

        if (!preg_match('/^n3-[a-z0-9-]{2,48}$/', $deviceId)) {
            return $this->json($response, [
                'success' => false,
                'error' => 'Identifiant invalide (attendu n3-…, lettres/chiffres/tirets).',
            ], 400);
        }
        if (!in_array($family, IotDeviceRepository::FAMILIES, true)) {
            return $this->json($response, [
                'success' => false,
                'error' => 'Famille inconnue. Autorisées : ' . implode(', ', IotDeviceRepository::FAMILIES),
            ], 400);
        }
        if ($label === '' || mb_strlen($label) > 128) {
            return $this->json($response, [
                'success' => false,
                'error' => 'Libellé requis (max 128 caractères).',
            ], 400);
        }
        if ($this->deviceRepository->findByDeviceId($deviceId) !== null) {
            return $this->json($response, [
                'success' => false,
                'error' => 'Cet identifiant existe déjà.',
            ], 409);
        }

        $defaults = self::FAMILY_DEFAULTS[$family];
        if ($env === '') {
            $env = $defaults['env'];
        }
        $pageKey = trim((string) ($params['page_key'] ?? '')) ?: ($defaults['page_key'] ?? null);
        $pageUrl = trim((string) ($params['page_url'] ?? '')) ?: ($defaults['page_url'] ?? null);
        $liveUrl = trim((string) ($params['live_health_url'] ?? '')) ?: ($defaults['live_health_url'] ?? null);
        $liveId = trim((string) ($params['live_module_id'] ?? ''));
        if ($liveId === '' && $liveUrl !== null && $liveUrl !== '') {
            $liveId = $deviceId;
        }
        if ($liveUrl === '') {
            $liveUrl = null;
            $liveId = null;
        }

        $user = $this->authService->getCurrentUser();

        try {
            $this->deviceRepository->upsert([
                'device_id' => $deviceId,
                'family' => $family,
                'board' => $board !== '' ? $board : null,
                'label' => $label,
                'env' => $env,
                'page_key' => $pageKey,
                'page_url' => $pageUrl,
                'live_module_id' => $liveId !== '' ? $liveId : null,
                'live_health_url' => $liveUrl,
                'active' => true,
                'sort_order' => 1000,
                'notes' => $notes !== '' ? $notes : null,
                'updated_by' => $user,
            ]);

            if ($showInNav && $pageKey !== null && $pageUrl !== null) {
                $this->navPageRepository->setActive($pageKey, $label, $pageUrl, true, $user);
            }

            // Board FFP3 / outputs legacy : enregistrer dans Boards si board numérique
            if ($board !== '' && ctype_digit($board) && !$this->boardRepository->exists($board)) {
                $this->boardRepository->create($board);
            }

            $this->logger->info('Appareil IoT ajouté depuis supervision', [
                'device_id' => $deviceId,
                'family' => $family,
                'who' => $user ?? 'n/a',
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Échec création appareil IoT', ['error' => $e->getMessage()]);

            return $this->json($response, [
                'success' => false,
                'error' => 'Enregistrement impossible.',
            ], 500);
        }

        return $this->json($response, [
            'success' => true,
            'device' => $this->deviceRepository->findByDeviceId($deviceId),
        ], 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        if (!$this->authService->isAdmin()) {
            return $this->json($response, [
                'success' => false,
                'error' => 'Action réservée aux administrateurs.',
            ], 403);
        }

        $deviceId = (string) ($args['id'] ?? '');
        $existing = $this->deviceRepository->findByDeviceId($deviceId);
        if ($existing === null) {
            return $this->json($response, [
                'success' => false,
                'error' => 'Appareil inconnu.',
            ], 404);
        }

        $params = RequestHelper::extractParams($request);
        $user = $this->authService->getCurrentUser();

        if (array_key_exists('active', $params)) {
            $this->deviceRepository->setActive($deviceId, $this->toBool($params['active']), $user);
        }

        $label = array_key_exists('label', $params)
            ? trim((string) $params['label'])
            : (string) $existing['label'];
        $board = array_key_exists('board', $params)
            ? trim((string) $params['board'])
            : (string) ($existing['board'] ?? '');
        $notes = array_key_exists('notes', $params)
            ? trim((string) $params['notes'])
            : (string) ($existing['notes'] ?? '');

        if ($label === '') {
            return $this->json($response, [
                'success' => false,
                'error' => 'Libellé requis.',
            ], 400);
        }

        try {
            $this->deviceRepository->upsert([
                'device_id' => $deviceId,
                'family' => (string) $existing['family'],
                'board' => $board !== '' ? $board : null,
                'label' => $label,
                'env' => (string) ($existing['env'] ?? 'prod'),
                'page_key' => $existing['page_key'] ?? null,
                'page_url' => $existing['page_url'] ?? null,
                'live_module_id' => $existing['live_module_id'] ?? null,
                'live_health_url' => $existing['live_health_url'] ?? null,
                'active' => array_key_exists('active', $params)
                    ? $this->toBool($params['active'])
                    : ((int) ($existing['active'] ?? 1) === 1),
                'sort_order' => (int) ($existing['sort_order'] ?? 1000),
                'notes' => $notes !== '' ? $notes : null,
                'updated_by' => $user,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Échec mise à jour appareil IoT', ['error' => $e->getMessage()]);

            return $this->json($response, [
                'success' => false,
                'error' => 'Mise à jour impossible.',
            ], 500);
        }

        return $this->json($response, [
            'success' => true,
            'device' => $this->deviceRepository->findByDeviceId($deviceId),
        ]);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if (!$this->authService->isAdmin()) {
            return $this->json($response, [
                'success' => false,
                'error' => 'Action réservée aux administrateurs.',
            ], 403);
        }

        $deviceId = (string) ($args['id'] ?? '');
        if ($this->deviceRepository->findByDeviceId($deviceId) === null) {
            return $this->json($response, [
                'success' => false,
                'error' => 'Appareil inconnu.',
            ], 404);
        }

        $this->deviceRepository->delete($deviceId);
        $this->logger->info('Appareil IoT supprimé', [
            'device_id' => $deviceId,
            'who' => $this->authService->getCurrentUser() ?? 'n/a',
        ]);

        return $this->json($response, ['success' => true]);
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'on', 'yes'], true);
        }

        return (bool) $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write((string) json_encode($data, JSON_UNESCAPED_UNICODE));

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}

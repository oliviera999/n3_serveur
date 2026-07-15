<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Security\AuthService;
use App\Service\LogService;
use App\Service\OperationalSettingsService;
use App\Util\RequestHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * API admin : réglages opérationnels (override BDD, repli .env).
 */
final class OperationalSettingsController
{
    public function __construct(
        private OperationalSettingsService $operationalSettings,
        private AuthService $authService,
        private LogService $logger,
    ) {
    }

    public function list(Request $request, Response $response): Response
    {
        return $this->json($response, [
            'success' => true,
            'data' => $this->operationalSettings->exportForUi(),
        ]);
    }

    public function update(Request $request, Response $response): Response
    {
        if (!$this->authService->isAdmin()) {
            return $this->json($response, [
                'success' => false,
                'error' => 'Action réservée aux administrateurs.',
            ], 403);
        }

        $params = RequestHelper::extractParams($request);
        $settings = $params['settings'] ?? [];
        if (!is_array($settings)) {
            return $this->json($response, [
                'success' => false,
                'error' => 'Format invalide.',
            ], 400);
        }

        $user = $this->authService->getCurrentUser();
        $updated = [];

        try {
            foreach ($settings as $envKey => $value) {
                if (!is_string($envKey)) {
                    continue;
                }
                $this->operationalSettings->set($envKey, $value, $user);
                $updated[] = $envKey;
            }
            $this->logger->info('Réglages opérationnels mis à jour', [
                'who' => $user ?? 'n/a',
                'keys' => $updated,
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->json($response, [
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        } catch (\Throwable $e) {
            $this->logger->error('Échec mise à jour réglages opérationnels', ['error' => $e->getMessage()]);

            return $this->json($response, [
                'success' => false,
                'error' => 'Enregistrement impossible.',
            ], 500);
        }

        return $this->json($response, [
            'success' => true,
            'data' => $this->operationalSettings->exportForUi(),
        ]);
    }

    public function reset(Request $request, Response $response): Response
    {
        if (!$this->authService->isAdmin()) {
            return $this->json($response, [
                'success' => false,
                'error' => 'Action réservée aux administrateurs.',
            ], 403);
        }

        $params = RequestHelper::extractParams($request);
        $group = isset($params['group']) ? trim((string) $params['group']) : '';
        $keys = $params['keys'] ?? [];

        try {
            if ($group !== '') {
                $this->operationalSettings->resetGroup($group);
            } elseif (is_array($keys) && $keys !== []) {
                /** @var list<string> $stringKeys */
                $stringKeys = array_values(array_filter($keys, 'is_string'));
                $this->operationalSettings->resetMany($stringKeys);
            } else {
                foreach (array_keys(\App\Config\OperationalSettingsCatalog::all()) as $envKey) {
                    $this->operationalSettings->reset($envKey);
                }
            }
            $this->logger->info('Réglages opérationnels réinitialisés (repli .env)', [
                'who' => $this->authService->getCurrentUser() ?? 'n/a',
                'group' => $group !== '' ? $group : null,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Échec réinitialisation réglages opérationnels', ['error' => $e->getMessage()]);

            return $this->json($response, [
                'success' => false,
                'error' => 'Réinitialisation impossible.',
            ], 500);
        }

        return $this->json($response, [
            'success' => true,
            'data' => $this->operationalSettings->exportForUi(),
        ]);
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

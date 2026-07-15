<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Config\Version;
use App\Security\AuthService;
use App\Service\HmacAuditLogger;
use App\Service\HmacPolicyService;
use App\Service\LogService;
use App\Service\TemplateRenderer;
use App\Util\RequestHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Page admin : consultation du journal d'audit HMAC et bascule on/off.
 */
final class HmacAuditController
{
    public function __construct(
        private TemplateRenderer $renderer,
        private HmacAuditLogger $hmacAuditLogger,
        private HmacPolicyService $hmacPolicyService,
        private AuthService $authService,
        private LogService $logger,
    ) {
    }

    public function showPage(Request $request, Response $response): Response
    {
        $html = $this->renderer->render('admin/hmac_audit.twig', [
            'page_title' => 'n3 IoT — Journal audit HMAC',
            'nav_active' => 'supervision',
            'version' => Version::getWithPrefix(),
            'audit_enabled' => $this->hmacAuditLogger->isEnabled(),
            'log_path' => $this->hmacAuditLogger->getLogFilePath(),
            'entry_count' => $this->hmacAuditLogger->countEntries(),
            'hmac_policy' => $this->hmacPolicyService->getStatus(),
        ]);

        $response->getBody()->write($html);

        return $response;
    }

    public function listEntries(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $limit = max(1, min(500, (int) ($params['limit'] ?? 200)));
        $offset = max(0, (int) ($params['offset'] ?? 0));

        return $this->json($response, [
            'success' => true,
            'enabled' => $this->hmacAuditLogger->isEnabled(),
            'total' => $this->hmacAuditLogger->countEntries(),
            'entries' => $this->hmacAuditLogger->readTail($limit, $offset),
            'policy' => $this->hmacPolicyService->getStatus(),
        ]);
    }

    public function toggle(Request $request, Response $response): Response
    {
        if (!$this->authService->isAdmin()) {
            return $this->json($response, [
                'success' => false,
                'error' => 'Action réservée aux administrateurs.',
            ], 403);
        }

        $params = RequestHelper::extractParams($request);
        $enabled = $this->toBool($params['enabled'] ?? false);

        try {
            $this->hmacAuditLogger->setEnabled($enabled, $this->authService->getCurrentUser());
            $this->logger->info('Journal audit HMAC ' . ($enabled ? 'activé' : 'désactivé'), [
                'who' => $this->authService->getCurrentUser() ?? 'n/a',
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Échec bascule journal audit HMAC', ['error' => $e->getMessage()]);

            return $this->json($response, [
                'success' => false,
                'error' => 'Enregistrement impossible.',
            ], 500);
        }

        return $this->json($response, [
            'success' => true,
            'enabled' => $this->hmacAuditLogger->isEnabled(),
        ]);
    }

    public function status(Request $request, Response $response): Response
    {
        return $this->json($response, [
            'success' => true,
            'enabled' => $this->hmacAuditLogger->isEnabled(),
            'total' => $this->hmacAuditLogger->countEntries(),
            'log_path' => $this->hmacAuditLogger->getLogFilePath(),
            'policy' => $this->hmacPolicyService->getStatus(),
        ]);
    }

    public function togglePolicy(Request $request, Response $response): Response
    {
        if (!$this->authService->isAdmin()) {
            return $this->json($response, [
                'success' => false,
                'error' => 'Action réservée aux administrateurs.',
            ], 403);
        }

        $params = RequestHelper::extractParams($request);
        $user = $this->authService->getCurrentUser();

        try {
            if (array_key_exists('strict', $params)) {
                $this->hmacPolicyService->setStrictMode($this->toBool($params['strict']), $user);
            }
            if (array_key_exists('nonce_required', $params)) {
                $this->hmacPolicyService->setNonceRequired($this->toBool($params['nonce_required']), $user);
            }
            $this->logger->info('Politique HMAC mise à jour depuis supervision', [
                'who' => $user ?? 'n/a',
                'policy' => $this->hmacPolicyService->getStatus(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Échec bascule politique HMAC', ['error' => $e->getMessage()]);

            return $this->json($response, [
                'success' => false,
                'error' => 'Enregistrement impossible.',
            ], 500);
        }

        return $this->json($response, [
            'success' => true,
            'policy' => $this->hmacPolicyService->getStatus(),
        ]);
    }

    public function resetPolicy(Request $request, Response $response): Response
    {
        if (!$this->authService->isAdmin()) {
            return $this->json($response, [
                'success' => false,
                'error' => 'Action réservée aux administrateurs.',
            ], 403);
        }

        $params = RequestHelper::extractParams($request);
        $target = trim((string) ($params['target'] ?? 'all'));

        try {
            if ($target === 'strict' || $target === 'all') {
                $this->hmacPolicyService->resetStrictMode();
            }
            if ($target === 'nonce' || $target === 'all') {
                $this->hmacPolicyService->resetNonceRequired();
            }
            $this->logger->info('Politique HMAC réinitialisée (repli .env)', [
                'who' => $this->authService->getCurrentUser() ?? 'n/a',
                'target' => $target,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Échec réinitialisation politique HMAC', ['error' => $e->getMessage()]);

            return $this->json($response, [
                'success' => false,
                'error' => 'Réinitialisation impossible.',
            ], 500);
        }

        return $this->json($response, [
            'success' => true,
            'policy' => $this->hmacPolicyService->getStatus(),
        ]);
    }

    public function policyStatus(Request $request, Response $response): Response
    {
        return $this->json($response, [
            'success' => true,
            'policy' => $this->hmacPolicyService->getStatus(),
        ]);
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

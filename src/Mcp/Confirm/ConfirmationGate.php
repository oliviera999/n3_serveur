<?php

declare(strict_types=1);

namespace App\Mcp\Confirm;

use App\Mcp\McpConfig;
use App\Mcp\McpContext;
use App\Mcp\ToolException;
use App\Mcp\ToolResult;
use App\Repository\McpConfirmRepository;
use App\Repository\UserRepository;
use App\Service\ControlAuditLogger;
use App\Service\LogService;
use App\Service\NotificationService;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Porte de confirmation par code à 6 chiffres (envoyé hors de Claude).
 *
 * Retourne un {@see ToolResult} non-erreur si un code doit être demandé, null si l'opération
 * peut continuer (aucune catégorie, skip, unlock actif, ou code valide consommé).
 */
final class ConfirmationGate
{
    public function __construct(
        private McpConfig $config,
        private McpConfirmRepository $repo,
        private NotificationService $notifications,
        private UserRepository $users,
        private ControlAuditLogger $audit,
        private LogService $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $arguments
     * @param list<string>         $categories
     * @param array{cible?: string, effet?: string, risques?: list<string>|string} $summary
     */
    public function check(
        string $tool,
        array $arguments,
        McpContext $context,
        array $categories,
        array $summary,
    ): ?ToolResult {
        $categories = array_values(array_unique(array_filter(
            $categories,
            static fn (string $c): bool => ConfirmCategory::isKnown($c)
        )));
        if ($categories === []) {
            return null;
        }

        $request = $context->request ?? (new ServerRequestFactory())->createServerRequest('POST', '/mcp');
        $who = 'mcp:' . $context->username;

        if ($this->isSkipped($categories) || $this->repo->hasActiveUnlock($context->userId, $context->clientId)) {
            $this->audit->logAction($request, 'mcp:confirm', 'bypass', [
                'tool' => $tool,
                'categories' => $categories,
                'mcp_user' => $context->username,
            ], true, 'skip_or_unlock', $who);
            $this->logger->info('[mcp-confirm] bypass tool={tool} user={user} categories={cat}', [
                'tool' => $tool,
                'user' => $context->username,
                'cat' => implode(',', $categories),
            ]);

            return null;
        }

        $confirmationId = isset($arguments['confirmation_id']) && is_string($arguments['confirmation_id'])
            ? $arguments['confirmation_id'] : '';
        $code = isset($arguments['confirmation_code']) && is_string($arguments['confirmation_code'])
            ? trim($arguments['confirmation_code']) : '';

        if ($confirmationId === '' || $code === '') {
            return $this->issue($tool, $arguments, $context, $categories, $summary, $request, $who);
        }

        $this->verify($confirmationId, $code, $tool, $arguments, $context, $request, $who);

        return null;
    }

    /**
     * @param list<string> $categories
     */
    private function isSkipped(array $categories): bool
    {
        $skip = $this->config->confirmSkipCategories();
        if ($skip === []) {
            return false;
        }
        // unlock n'est jamais dispensable
        $needed = array_values(array_filter(
            $categories,
            static fn (string $c): bool => $c !== ConfirmCategory::UNLOCK
        ));
        if ($needed === []) {
            return false;
        }
        if (in_array('all', $skip, true)) {
            return true;
        }
        foreach ($needed as $cat) {
            if (!in_array($cat, $skip, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $arguments
     * @param list<string>         $categories
     * @param array<string, mixed> $summary
     */
    private function issue(
        string $tool,
        array $arguments,
        McpContext $context,
        array $categories,
        array $summary,
        \Psr\Http\Message\ServerRequestInterface $request,
        string $who,
    ): ToolResult {
        $recent = $this->repo->countRecentIssues($context->userId, time() - 3600);
        if ($recent >= McpConfirmRepository::MAX_ISSUES_PER_HOUR) {
            throw new ToolException(
                'Trop de codes de confirmation demandés (maximum '
                . McpConfirmRepository::MAX_ISSUES_PER_HOUR . ' / heure). Réessayez plus tard.',
                'confirm_rate_limited'
            );
        }

        $recipient = $this->resolveRecipient($context);
        $code = sprintf('%06d', random_int(0, 999999));
        $confirmationId = bin2hex(random_bytes(16));
        $fingerprint = ArgsFingerprint::of($arguments);
        $expiresAt = date('Y-m-d H:i:s', time() + McpConfirmRepository::CODE_TTL_SECONDS);

        $this->repo->createConfirmation(
            $confirmationId,
            $context->userId,
            $context->clientId,
            $tool,
            $fingerprint,
            $categories,
            $summary,
            ConfirmCodeHasher::hash($code),
        );

        $risks = $summary['risques'] ?? [];
        if (is_string($risks)) {
            $risks = [$risks];
        }
        $body = "Confirmation MCP requise\n\n"
            . 'Outil : ' . $tool . "\n"
            . 'Cible : ' . (string) ($summary['cible'] ?? '—') . "\n"
            . 'Effet : ' . (string) ($summary['effet'] ?? '—') . "\n"
            . 'Risques : ' . implode(' ; ', array_map('strval', $risks)) . "\n\n"
            . 'Code à usage unique (valide 10 min) : ' . $code . "\n"
            . 'confirmation_id : ' . $confirmationId . "\n\n"
            . "Ne transmettez ce code qu'à Claude dans la conversation en rappelant l'outil "
            . 'avec confirmation_id et confirmation_code. Ne le collez nulle part ailleurs.';

        $sent = $this->notifications->sendConfirmationCode(
            $recipient,
            '[MCP] Code de confirmation ' . substr($confirmationId, 0, 8),
            $body
        );
        if (!$sent) {
            $this->repo->invalidateConfirmation($confirmationId);
            throw new ToolException('Échec d\'envoi du code de confirmation par e-mail.', 'confirm_send_failed');
        }

        $this->audit->logAction($request, 'mcp:confirm', 'issue', [
            'tool' => $tool,
            'confirmation_id' => $confirmationId,
            'categories' => $categories,
            'mcp_user' => $context->username,
        ], true, null, $who);
        $this->logger->info('[mcp-confirm] issue tool={tool} user={user} id={id} categories={cat}', [
            'tool' => $tool,
            'user' => $context->username,
            'id' => $confirmationId,
            'cat' => implode(',', $categories),
        ]);

        return ToolResult::json([
            'confirmation_required' => true,
            'confirmation_id' => $confirmationId,
            'expires_at' => $expiresAt,
            'summary' => [
                'cible' => $summary['cible'] ?? null,
                'effet' => $summary['effet'] ?? null,
                'risques' => $risks,
                'categories' => $categories,
            ],
            'sent_to' => self::maskEmail($recipient),
            'next_step' => 'Lisez le code à 6 chiffres dans votre e-mail, puis rappelez cet outil '
                . 'avec les MÊMES arguments plus confirmation_id et confirmation_code.',
        ]);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function verify(
        string $confirmationId,
        string $code,
        string $tool,
        array $arguments,
        McpContext $context,
        \Psr\Http\Message\ServerRequestInterface $request,
        string $who,
    ): void {
        $row = $this->repo->findConfirmation($confirmationId);
        if ($row === null || (int) $row['user_id'] !== $context->userId) {
            $this->failAudit($request, $who, $tool, $confirmationId, 'not_found');
            throw new ToolException('Confirmation introuvable (ou autre utilisateur).', 'confirm_not_found');
        }
        if ($row['consumed_at'] !== null) {
            $this->failAudit($request, $who, $tool, $confirmationId, 'reused');
            throw new ToolException('Ce code a déjà été utilisé.', 'confirm_reused');
        }
        if (strtotime((string) $row['expires_at']) < time()) {
            $this->repo->invalidateConfirmation($confirmationId);
            $this->failAudit($request, $who, $tool, $confirmationId, 'expired');
            throw new ToolException('Code de confirmation expiré (10 min).', 'confirm_expired');
        }
        if ((string) $row['tool'] !== $tool) {
            $attempts = $this->repo->incrementAttempts($confirmationId);
            if ($attempts >= McpConfirmRepository::MAX_ATTEMPTS) {
                $this->repo->invalidateConfirmation($confirmationId);
            }
            $this->failAudit($request, $who, $tool, $confirmationId, 'tool_mismatch');
            throw new ToolException('Le code ne correspond pas à cet outil.', 'confirm_tool_mismatch');
        }
        if ((string) $row['client_id'] !== $context->clientId) {
            $this->failAudit($request, $who, $tool, $confirmationId, 'client_mismatch');
            throw new ToolException('Le code ne correspond pas à ce client OAuth.', 'confirm_client_mismatch');
        }

        $fingerprint = ArgsFingerprint::of($arguments);
        if (!hash_equals((string) $row['args_fingerprint'], $fingerprint)) {
            $attempts = $this->repo->incrementAttempts($confirmationId);
            if ($attempts >= McpConfirmRepository::MAX_ATTEMPTS) {
                $this->repo->invalidateConfirmation($confirmationId);
            }
            $this->failAudit($request, $who, $tool, $confirmationId, 'args_changed');
            throw new ToolException(
                'Les arguments ont changé depuis l\'émission du code (essai '
                . $attempts . '/' . McpConfirmRepository::MAX_ATTEMPTS . ').',
                'confirm_args_changed'
            );
        }

        if (!preg_match('/^\d{6}$/', $code) || !ConfirmCodeHasher::verify($code, (string) $row['code_hmac'])) {
            $attempts = $this->repo->incrementAttempts($confirmationId);
            if ($attempts >= McpConfirmRepository::MAX_ATTEMPTS) {
                $this->repo->invalidateConfirmation($confirmationId);
            }
            $this->failAudit($request, $who, $tool, $confirmationId, 'bad_code');
            throw new ToolException(
                'Code incorrect (essai ' . $attempts . '/' . McpConfirmRepository::MAX_ATTEMPTS . ').',
                'confirm_bad_code'
            );
        }

        if (!$this->repo->consumeConfirmation($confirmationId)) {
            throw new ToolException('Ce code a déjà été utilisé.', 'confirm_reused');
        }

        $this->audit->logAction($request, 'mcp:confirm', 'ok', [
            'tool' => $tool,
            'confirmation_id' => $confirmationId,
            'mcp_user' => $context->username,
        ], true, null, $who);
        $this->logger->info('[mcp-confirm] ok tool={tool} user={user} id={id}', [
            'tool' => $tool,
            'user' => $context->username,
            'id' => $confirmationId,
        ]);
    }

    private function resolveRecipient(McpContext $context): string
    {
        $email = $context->email;
        if ($email === null || $email === '') {
            $user = $this->users->findById($context->userId);
            $email = $user?->email;
        }
        if ($email !== null && $email !== '') {
            return $email;
        }
        $fallback = $this->notifications->recipient();
        if ($fallback !== '' && $fallback !== 'user@example.com') {
            return $fallback;
        }
        throw new ToolException(
            'Aucun e-mail pour envoyer le code (compte sans e-mail et NOTIF_EMAIL_RECIPIENT vide).',
            'confirm_no_recipient'
        );
    }

    private function failAudit(
        \Psr\Http\Message\ServerRequestInterface $request,
        string $who,
        string $tool,
        string $confirmationId,
        string $reason,
    ): void {
        $this->audit->logAction($request, 'mcp:confirm', 'fail', [
            'tool' => $tool,
            'confirmation_id' => $confirmationId,
            'reason' => $reason,
        ], false, $reason, $who);
        $this->logger->info('[mcp-confirm] fail tool={tool} id={id} reason={reason}', [
            'tool' => $tool,
            'id' => $confirmationId,
            'reason' => $reason,
        ]);
    }

    public static function maskEmail(string $email): string
    {
        $parts = explode('@', $email, 2);
        if (count($parts) !== 2) {
            return '***';
        }
        $local = $parts[0];
        $masked = ($local[0] ?? '*') . '***';

        return $masked . '@' . $parts[1];
    }
}

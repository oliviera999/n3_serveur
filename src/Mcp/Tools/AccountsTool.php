<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\McpConfig;
use App\Mcp\McpContext;
use App\Mcp\Support\EnvironmentScope;
use App\Mcp\Support\FieldCatalog;
use App\Mcp\ToolResult;
use App\Repository\McpReadRepository;
use App\Repository\UserRepository;
use App\Service\NotificationService;

/**
 * `iot_accounts` — noms et e-mails des comptes / destinataires (admin + MCP_PRIVACY_BYPASS).
 */
final class AccountsTool extends AbstractTool
{
    public function __construct(
        McpConfig $config,
        private UserRepository $users,
        private NotificationService $notifications,
        private McpReadRepository $repo,
    ) {
        parent::__construct($config);
    }

    public function name(): string
    {
        return 'iot_accounts';
    }

    public function title(): string
    {
        return 'Comptes et destinataires (admin)';
    }

    public function description(): string
    {
        return 'ADMIN + MCP_PRIVACY_BYPASS — liste les comptes n3_users (sans mot de passe ni hash) '
            . 'et les destinataires de notification (NOTIF_EMAIL_RECIPIENT, GPIO mail/mailNotif). '
            . 'Les secrets ne sont jamais renvoyés.';
    }

    public function requiredScope(): string
    {
        return McpConfig::SCOPE_READ;
    }

    public function isAvailableFor(McpContext $context): bool
    {
        return $this->config->isPrivacyBypassEnabled() && $context->isAdmin();
    }

    public function inputSchema(): array
    {
        return $this->objectSchema([]);
    }

    public function call(array $arguments, McpContext $context): ToolResult
    {
        AdminGuard::assertAdmin($context);
        if (!$context->canSeePersonalData()) {
            return ToolResult::error('MCP_PRIVACY_BYPASS désactivé.', 'forbidden');
        }

        $accounts = [];
        foreach ($this->users->findAll() as $user) {
            $accounts[] = [
                'id' => $user->id,
                'username' => $user->username,
                'display_name' => $user->displayName,
                'email' => $user->email,
                'role' => $user->role,
                'is_active' => $user->isActive,
                'last_login_at' => $user->lastLoginAt,
            ];
        }

        $recipients = [
            'notif_email_recipient' => $this->notifications->recipient(),
        ];
        foreach ($this->config->allowedEnvs() as $env) {
            foreach (FieldCatalog::OUTPUT_FAMILIES as $family) {
                $mail = EnvironmentScope::run($env, function () use ($family): array {
                    $m = $this->repo->output($family, 100);
                    $n = $this->repo->output($family, 101);

                    return [
                        'mail' => $m['state'] ?? null,
                        'mailNotif' => $n['state'] ?? null,
                    ];
                });
                $recipients[$family . ':' . $env] = $mail;
            }
        }

        return ToolResult::json([
            'users' => $accounts,
            'recipients' => $recipients,
            'note' => 'Aucun secret (password_hash, jetons, clés API) n\'est inclus.',
        ]);
    }
}

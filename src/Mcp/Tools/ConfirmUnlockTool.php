<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Confirm\ConfirmationGate;
use App\Mcp\Confirm\ConfirmCategory;
use App\Mcp\McpConfig;
use App\Mcp\McpContext;
use App\Mcp\ToolException;
use App\Mcp\ToolResult;
use App\Repository\McpConfirmRepository;

/**
 * `iot_confirm_unlock` — ouvre une fenêtre sans code après confirmation (productivité).
 */
final class ConfirmUnlockTool extends AbstractTool
{
    public function __construct(
        McpConfig $config,
        private ConfirmationGate $confirm,
        private McpConfirmRepository $repo,
    ) {
        parent::__construct($config);
    }

    public function name(): string
    {
        return 'iot_confirm_unlock';
    }

    public function title(): string
    {
        return 'Déverrouiller les confirmations (fenêtre)';
    }

    public function description(): string
    {
        return 'ADMIN — après un code valide, ouvre une fenêtre de MCP_CONFIRM_UNLOCK_MINUTES '
            . 'pendant laquelle les confirmations (sauf unlock) sont dispensées pour ce compte et ce client. '
            . 'La catégorie unlock n\'est jamais contournée par MCP_CONFIRM_SKIP.';
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function requiredScope(): string
    {
        return McpConfig::SCOPE_READ;
    }

    public function isAvailableFor(McpContext $context): bool
    {
        return $this->config->confirmUnlockMinutes() > 0 && $context->isAdmin();
    }

    public function annotations(): array
    {
        return [
            'title' => $this->title(),
            'readOnlyHint' => false,
            'destructiveHint' => true,
            'idempotentHint' => false,
            'openWorldHint' => false,
        ];
    }

    public function inputSchema(): array
    {
        return $this->objectSchema([
            'reason' => ['type' => 'string', 'minLength' => 10, 'maxLength' => 200],
            'confirmation_id' => ['type' => 'string', 'pattern' => '^[a-f0-9]{32}$'],
            'confirmation_code' => ['type' => 'string', 'pattern' => '^[0-9]{6}$'],
        ], ['reason']);
    }

    public function call(array $arguments, McpContext $context): ToolResult
    {
        AdminGuard::assertAdmin($context);
        $minutes = $this->config->confirmUnlockMinutes();
        if ($minutes <= 0) {
            throw new ToolException('Déverrouillage désactivé (MCP_CONFIRM_UNLOCK_MINUTES=0).', 'forbidden');
        }
        $reason = (string) $arguments['reason'];

        $pending = $this->confirm->check($this->name(), $arguments, $context, [ConfirmCategory::UNLOCK], [
            'cible' => 'compte ' . $context->username . ' / client ' . $context->clientId,
            'effet' => sprintf('Fenêtre de %d min sans codes de confirmation', $minutes),
            'risques' => [
                'Les opérations sensibles (SQL DDL, reset, OTA, photos, levées bio) pourront s\'exécuter sans nouveau code.',
                'Motif : ' . $reason,
            ],
        ]);
        if ($pending !== null) {
            return $pending;
        }

        $this->repo->createUnlock($context->userId, $context->clientId, $minutes);
        $expires = date('Y-m-d H:i:s', time() + ($minutes * 60));

        return ToolResult::json([
            'unlocked' => true,
            'expires_at' => $expires,
            'minutes' => $minutes,
        ]);
    }
}

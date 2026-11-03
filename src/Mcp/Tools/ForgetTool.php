<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\McpConfig;
use App\Mcp\McpContext;
use App\Mcp\Memory\MemoryService;
use App\Mcp\ToolResult;

/**
 * `iot_forget` — supprime une note de la mémoire (auteur de la note ou administrateur).
 */
final class ForgetTool extends AbstractTool
{
    public function __construct(McpConfig $config, private MemoryService $memory)
    {
        parent::__construct($config);
    }

    public function name(): string
    {
        return 'iot_forget';
    }

    public function title(): string
    {
        return 'Oublier une note';
    }

    public function description(): string
    {
        return 'Supprime définitivement une note de la mémoire, par son identifiant (champ « id » renvoyé par iot_recall). '
            . 'Réservé à l\'auteur de la note ou à un administrateur ; les notes semées par la migration ne sont supprimables '
            . 'que par un administrateur. Pour corriger une note, préférer iot_remember avec le même sujet.';
    }

    public function inputSchema(): array
    {
        return $this->objectSchema([
            'id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Identifiant de la note (iot_recall).'],
        ], ['id']);
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function annotations(): array
    {
        return [
            'title' => $this->title(),
            'readOnlyHint' => false,
            'destructiveHint' => true,
            'idempotentHint' => true,
            'openWorldHint' => false,
        ];
    }

    public function call(array $arguments, McpContext $context): ToolResult
    {
        return ToolResult::json($this->memory->forget($context, (int) $arguments['id']));
    }
}

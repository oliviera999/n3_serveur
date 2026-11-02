<?php

declare(strict_types=1);

namespace App\Mcp;

/**
 * Registre des outils MCP (ordre stable = ordre de déclaration).
 *
 * Les outils de pilotage (scope `iot.control`) ne sont listés que si le pilotage est
 * activé (`MCP_CONTROL_ENABLED=true`) ET que l'appelant détient le scope effectif.
 */
final class ToolRegistry
{
    /** @var array<string, Tool> */
    private array $tools = [];

    /**
     * @param iterable<Tool> $tools
     */
    public function __construct(iterable $tools, private McpConfig $config)
    {
        foreach ($tools as $tool) {
            if (isset($this->tools[$tool->name()])) {
                throw new \LogicException('Outil MCP en double : ' . $tool->name());
            }
            $this->tools[$tool->name()] = $tool;
        }
    }

    public function get(string $name): ?Tool
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * @return list<Tool>
     */
    public function all(): array
    {
        return array_values($this->tools);
    }

    /**
     * Outils visibles par l'appelant.
     *
     * @return list<Tool>
     */
    public function visibleFor(McpContext $context): array
    {
        $out = [];
        foreach ($this->tools as $tool) {
            if ($tool->requiredScope() === McpConfig::SCOPE_CONTROL
                && (!$this->config->isControlEnabled() || !$context->isOperator()
                    || !$context->hasScope(McpConfig::SCOPE_CONTROL))) {
                continue;
            }
            $out[] = $tool;
        }

        return $out;
    }

    /**
     * Définition MCP d'un outil (`tools/list`).
     *
     * @return array<string, mixed>
     */
    public static function definition(Tool $tool): array
    {
        return [
            'name' => $tool->name(),
            'title' => $tool->title(),
            'description' => $tool->description(),
            'inputSchema' => $tool->inputSchema(),
            'annotations' => $tool->annotations(),
        ];
    }
}

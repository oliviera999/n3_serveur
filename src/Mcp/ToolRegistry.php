<?php

declare(strict_types=1);

namespace App\Mcp;

/**
 * Registre des outils MCP (ordre stable = ordre de déclaration).
 *
 * La visibilité repose sur {@see Tool::isAvailableFor()} (flags .env + rôle + scopes).
 */
final class ToolRegistry
{
    /** @var array<string, Tool> */
    private array $tools = [];

    /**
     * @param iterable<Tool> $tools
     */
    public function __construct(iterable $tools, McpConfig $config)
    {
        // $config conservé pour signature stable (DI) ; la visibilité est dans Tool::isAvailableFor().
        unset($config);
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
            if (!$tool->isAvailableFor($context)) {
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

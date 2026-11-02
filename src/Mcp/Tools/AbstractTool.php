<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\McpConfig;
use App\Mcp\McpContext;
use App\Mcp\Support\EnvironmentScope;
use App\Mcp\Support\FieldCatalog;
use App\Mcp\Tool;
use App\Mcp\ToolException;
use App\Mcp\ToolResult;

/**
 * Base commune des outils MCP : schéma de l'argument `env`, bascule d'environnement
 * garantie ({@see EnvironmentScope}), enveloppe de réponse (`env`, `is_production`).
 */
abstract class AbstractTool implements Tool
{
    public function __construct(protected McpConfig $config)
    {
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function requiredScope(): string
    {
        return McpConfig::SCOPE_READ;
    }

    /**
     * @return array<string, bool|string>
     */
    public function annotations(): array
    {
        return [
            'title' => $this->title(),
            'readOnlyHint' => true,
            'destructiveHint' => false,
            'idempotentHint' => true,
            'openWorldHint' => false,
        ];
    }

    /**
     * Schéma de l'argument `env` (énumération des environnements autorisés).
     *
     * @return array<string, mixed>
     */
    protected function envProperty(bool $control = false): array
    {
        $allowed = $control ? $this->config->controlAllowedEnvs() : $this->config->allowedEnvs();
        $default = in_array('prod', $allowed, true) ? 'prod' : ($allowed[0] ?? 'prod');

        return [
            'type' => 'string',
            'enum' => $allowed === [] ? ['prod'] : $allowed,
            'default' => $default,
            'description' => 'Environnement BDD. « prod » = installation réelle (aquaponie WROOM, serre, météo) ; '
                . '« s3 » = aquamobile ESP32-S3 (PRODUCTION aussi) ; les autres sont des bancs de TEST. '
                . 'Valeurs autorisées par ce serveur : ' . implode(', ', $allowed === [] ? ['prod'] : $allowed) . '.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function familyProperty(bool $withEnergie = true, string $description = 'Famille d\'appareils.'): array
    {
        $families = $withEnergie ? FieldCatalog::FAMILIES : FieldCatalog::OUTPUT_FAMILIES;
        $labels = array_map(static fn (string $f): string => $f . ' = ' . FieldCatalog::label($f), $families);

        return [
            'type' => 'string',
            'enum' => $families,
            'description' => $description . ' ' . implode(' ; ', $labels) . '.',
        ];
    }

    /**
     * @param array<string, mixed> $properties
     * @param list<string>         $required
     * @return array<string, mixed>
     */
    protected function objectSchema(array $properties, array $required = []): array
    {
        $schema = [
            'type' => 'object',
            'properties' => $properties === [] ? new \stdClass() : $properties,
            'additionalProperties' => false,
        ];
        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * Exécute le callback dans l'environnement demandé (validé) et enveloppe le résultat.
     *
     * @param array<string, mixed> $arguments
     * @param callable(string): array<string, mixed> $callback reçoit l'environnement
     */
    protected function inEnv(array $arguments, callable $callback, ?string $family = null, bool $control = false): ToolResult
    {
        $env = $this->resolveEnv($arguments, $control);
        $data = EnvironmentScope::run($env, static fn (): array => $callback($env));

        return ToolResult::json(array_merge([
            'env' => $env,
            'is_production' => $family !== null
                ? FieldCatalog::isProductionTarget($family, $env)
                : !in_array($env, McpConfig::TEST_ENVIRONMENTS, true),
        ], $data));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    protected function resolveEnv(array $arguments, bool $control = false): string
    {
        $allowed = $control ? $this->config->controlAllowedEnvs() : $this->config->allowedEnvs();
        $env = is_string($arguments['env'] ?? null) ? $arguments['env'] : 'prod';
        if (!in_array($env, $allowed, true)) {
            throw new ToolException(sprintf(
                'Environnement « %s » non autorisé ici. Autorisés : %s.',
                $env,
                $allowed === [] ? '(aucun)' : implode(', ', $allowed)
            ), 'env_not_allowed');
        }

        return $env;
    }

    protected static function ageSeconds(?string $sqlDate, ?int $now = null): ?int
    {
        if ($sqlDate === null || $sqlDate === '') {
            return null;
        }
        $ts = strtotime($sqlDate);

        return $ts === false ? null : max(0, ($now ?? time()) - $ts);
    }

    /**
     * Arguments communs de fenêtre temporelle.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function windowProperties(string $defaultHint): array
    {
        return [
            'from' => [
                'type' => 'string',
                'maxLength' => 40,
                'description' => 'Début : date ISO 8601 (2026-11-01 ou 2026-11-01T08:00), « now », ou relatif (-30m, -12h, -7d). '
                    . 'Défaut : ' . $defaultHint . '.',
            ],
            'to' => [
                'type' => 'string',
                'maxLength' => 40,
                'description' => 'Fin (même format). Défaut : maintenant.',
            ],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Mcp;

/**
 * Contrat d'un outil MCP exposé par le serveur iot.olution.info.
 *
 * Chaque outil :
 *  - porte un nom STABLE (snake_case, préfixe `iot_`) — Claude le mémorise ;
 *  - décrit précisément, en français, ce qu'il fait et comment interpréter ses données ;
 *  - publie un `inputSchema` JSON Schema strict (`additionalProperties: false`) validé
 *    par {@see Support\SchemaValidator} AVANT l'appel de {@see call()} ;
 *  - déclare ses annotations MCP (`readOnlyHint`, `destructiveHint`, …).
 *
 * Les outils de lecture ne doivent JAMAIS écrire : le serveur les exécute dans une
 * transaction `READ ONLY` (voir {@see McpServer}).
 */
interface Tool
{
    public function name(): string;

    public function title(): string;

    public function description(): string;

    /**
     * @return array<string, mixed> JSON Schema (type object)
     */
    public function inputSchema(): array;

    /**
     * @return array<string, bool|string>
     */
    public function annotations(): array;

    /** Vrai si l'outil ne modifie aucune donnée (exécution en transaction READ ONLY). */
    public function isReadOnly(): bool;

    /** Scope OAuth exigé (`iot.read` ou `iot.control`). */
    public function requiredScope(): string;

    /**
     * Visibilité pour l'appelant (flags .env + rôle). Un outil non visible est masqué
     * de tools/list et refusé à l'appel (forbidden).
     */
    public function isAvailableFor(McpContext $context): bool;

    /**
     * @param array<string, mixed> $arguments Arguments déjà validés contre le schéma
     */
    public function call(array $arguments, McpContext $context): ToolResult;
}

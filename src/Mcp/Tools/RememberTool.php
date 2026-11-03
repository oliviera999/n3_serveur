<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\McpConfig;
use App\Mcp\McpContext;
use App\Mcp\Memory\MemoryService;
use App\Mcp\ToolResult;

/**
 * `iot_remember` — enregistre (ou met à jour) une note de la mémoire persistante du connecteur.
 *
 * Écrit dans `mcp_memory` uniquement (jamais dans les tables de mesures ou de pilotage) :
 * l'outil n'est donc PAS exécuté en transaction lecture seule. Scope `iot.read` suffisant.
 */
final class RememberTool extends AbstractTool
{
    public function __construct(McpConfig $config, private MemoryService $memory)
    {
        parent::__construct($config);
    }

    public function name(): string
    {
        return 'iot_remember';
    }

    public function title(): string
    {
        return 'Mémoriser une note';
    }

    public function description(): string
    {
        return 'Enregistre dans la mémoire persistante du connecteur un fait STABLE, utile aux prochaines sessions : '
            . 'convention du site, interprétation d\'un champ, emplacement d\'un réglage, diagnostic confirmé, décision '
            . 'de l\'enseignant. Un sujet déjà existant (même portée) est MIS À JOUR et marqué revérifié à la date du jour : '
            . 'c\'est aussi le moyen de confirmer une note « à revérifier ». Portée « site » (partagée par tous les comptes, '
            . 'défaut) ou « user » (privée). Ne pas mémoriser de mesures instantanées (elles changent), ni aucun secret : '
            . 'un contenu qui ressemble à une clé, un jeton ou un mot de passe est refusé. Taille limitée.';
    }

    public function inputSchema(): array
    {
        return $this->objectSchema([
            'subject' => [
                'type' => 'string', 'minLength' => 1, 'maxLength' => MemoryService::MAX_SUBJECT,
                'description' => 'Sujet court et stable, réutilisé pour mettre à jour (ex. « Seuil aquarium bas », « Réserve qui fuit »).',
            ],
            'content' => [
                'type' => 'string', 'minLength' => 1, 'maxLength' => $this->config->memoryMaxChars(),
                'description' => 'Le fait à retenir, autonome et sourcé (où l\'avoir vérifié). Sans secret.',
            ],
            'tags' => [
                'type' => 'array', 'maxItems' => MemoryService::MAX_TAGS, 'uniqueItems' => true,
                'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => MemoryService::MAX_TAG_LENGTH],
                'description' => 'Étiquettes (ex. ffp3, gpio, cron, diagnostic).',
            ],
            'scope' => [
                'type' => 'string', 'enum' => ['site', 'user'], 'default' => 'site',
                'description' => 'site = visible par tous les comptes ; user = note privée de ce compte.',
            ],
        ], ['subject', 'content']);
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
            'destructiveHint' => false,
            'idempotentHint' => true,
            'openWorldHint' => false,
        ];
    }

    public function call(array $arguments, McpContext $context): ToolResult
    {
        $tags = array_values(array_map('strval', (array) ($arguments['tags'] ?? [])));

        return ToolResult::json($this->memory->remember(
            $context,
            (string) $arguments['subject'],
            (string) $arguments['content'],
            $tags,
            (string) ($arguments['scope'] ?? 'site')
        ));
    }
}

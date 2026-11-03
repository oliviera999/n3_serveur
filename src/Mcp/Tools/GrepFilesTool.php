<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Files\SafeFileReader;
use App\Mcp\McpConfig;
use App\Mcp\McpContext;
use App\Mcp\ToolResult;

/**
 * `iot_grep_files` — recherche d'un motif dans les fichiers d'une racine autorisée.
 */
final class GrepFilesTool extends AbstractTool
{
    public function __construct(McpConfig $config, private SafeFileReader $files)
    {
        parent::__construct($config);
    }

    public function name(): string
    {
        return 'iot_grep_files';
    }

    public function title(): string
    {
        return 'Chercher dans les fichiers du serveur';
    }

    public function description(): string
    {
        return 'Administrateur, lecture seule. Cherche un motif dans un fichier ou un dossier autorisé (voir iot_list_files) : '
            . 'texte LITTÉRAL par défaut, ou expression régulière bornée (« regex »: true ; ni récursion, ni référence arrière). '
            . 'Renvoie chemin, numéro de ligne, ligne et contexte facultatif ; nombre de résultats et de fichiers plafonné. '
            . 'Exemples : « [mcp] » dans logs, « ERROR » dans logs avec glob « cronlog-2026-11-*.txt », « [run-cron] » dans '
            . 'php-errors. La recherche porte sur le texte déjà masqué : les secrets ne peuvent être ni lus ni devinés.';
    }

    public function inputSchema(): array
    {
        return $this->objectSchema([
            'path' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 300, 'description' => '<racine>[/<chemin>] : fichier ou dossier.'],
            'pattern' => ['type' => 'string', 'minLength' => 1, 'maxLength' => SafeFileReader::MAX_PATTERN_LENGTH, 'description' => 'Motif cherché.'],
            'regex' => ['type' => 'boolean', 'default' => false, 'description' => 'Interpréter le motif comme une expression régulière PCRE (bornée).'],
            'case_sensitive' => ['type' => 'boolean', 'default' => false, 'description' => 'Respecter la casse.'],
            'glob' => ['type' => 'string', 'maxLength' => 60, 'description' => 'Filtre de nom de fichier dans un dossier (ex. « *.txt »).'],
            'max_results' => ['type' => 'integer', 'minimum' => 1, 'maximum' => SafeFileReader::MAX_GREP_MATCHES, 'default' => 50, 'description' => 'Nombre maximal de lignes trouvées.'],
            'context' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 3, 'default' => 0, 'description' => 'Lignes de contexte avant/après.'],
        ], ['path', 'pattern']);
    }

    public function call(array $arguments, McpContext $context): ToolResult
    {
        AdminGuard::assertAdmin($context);

        return ToolResult::json($this->files->grep(
            (string) $arguments['path'],
            (string) $arguments['pattern'],
            ($arguments['regex'] ?? false) === true,
            ($arguments['case_sensitive'] ?? false) === true,
            isset($arguments['glob']) && $arguments['glob'] !== '' ? (string) $arguments['glob'] : null,
            (int) ($arguments['max_results'] ?? 50),
            (int) ($arguments['context'] ?? 0)
        ));
    }
}

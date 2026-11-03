<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Files\SafeFileReader;
use App\Mcp\McpConfig;
use App\Mcp\McpContext;
use App\Mcp\ToolResult;

/**
 * `iot_list_files` — racines autorisées et contenu d'un dossier (lecture seule, administrateur).
 */
final class ListFilesTool extends AbstractTool
{
    public function __construct(McpConfig $config, private SafeFileReader $files)
    {
        parent::__construct($config);
    }

    public function name(): string
    {
        return 'iot_list_files';
    }

    public function title(): string
    {
        return 'Lister les fichiers du serveur';
    }

    public function description(): string
    {
        return 'Administrateur, lecture seule. Sans « path » : les racines consultables (journaux applicatifs et leurs rotations '
            . 'datées, var/, journal d\'erreurs PHP, config/, docs/, migrations/, .htaccess, run-cron.php). Avec « path » '
            . '(format <racine>/<chemin>, ex. « logs », « docs/deployment ») : fichiers et dossiers, taille, date de '
            . 'modification. Les dépôts git, dépendances, cache compilé et fichiers de clés ne sont jamais listés. '
            . 'Lire ensuite avec iot_read_file (tail pour les journaux) ou chercher avec iot_grep_files.';
    }

    public function inputSchema(): array
    {
        return $this->objectSchema([
            'path' => ['type' => 'string', 'maxLength' => 300, 'description' => '<racine>/<chemin relatif>. Omettre pour la liste des racines.'],
            'recursive' => ['type' => 'boolean', 'default' => false, 'description' => 'Descendre dans les sous-dossiers (profondeur ≤ 4, ≤ 500 entrées).'],
            'glob' => ['type' => 'string', 'maxLength' => 60, 'description' => 'Filtre de nom de fichier (ex. « *.md », « cronlog-2026-11-*.txt »).'],
        ]);
    }

    public function call(array $arguments, McpContext $context): ToolResult
    {
        AdminGuard::assertAdmin($context);
        $path = isset($arguments['path']) ? trim((string) $arguments['path']) : '';
        if ($path === '') {
            return ToolResult::json([
                'roots' => $this->files->roots(),
                'note' => 'Désigner un fichier par <racine>/<chemin>. Les valeurs des fichiers .env et les secrets des journaux sont toujours masqués.',
            ]);
        }

        return ToolResult::json($this->files->list(
            $path,
            ($arguments['recursive'] ?? false) === true,
            isset($arguments['glob']) && $arguments['glob'] !== '' ? (string) $arguments['glob'] : null
        ));
    }
}

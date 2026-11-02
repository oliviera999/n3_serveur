<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Config\GalleryConfig;
use App\Mcp\McpConfig;
use App\Mcp\McpContext;
use App\Mcp\Support\GalleryReader;
use App\Mcp\Support\TimeWindow;
use App\Mcp\ToolResult;

/**
 * `iot_gallery_list` — photos d'une galerie (paginé) + infos timelapse.
 */
final class GalleryListTool extends AbstractTool
{
    public function __construct(McpConfig $config, private GalleryReader $gallery)
    {
        parent::__construct($config);
    }

    public function name(): string
    {
        return 'iot_gallery_list';
    }

    public function title(): string
    {
        return 'Liste des photos d\'une galerie';
    }

    public function description(): string
    {
        return 'Liste paginée (plus récentes d\'abord) des photos prises par les caméras ESP32-CAM : ffp3 = aquaponie, '
            . 'n3pp = serre/élevage, msp1 = potager. Chaque entrée donne le nom de fichier, la date de capture et si une copie '
            . 'normalisée (luminosité lissée, utilisée pour le timelapse) existe. Filtrable par période. Utiliser ensuite '
            . 'iot_gallery_latest avec « filename » pour regarder une photo précise. Indique aussi l\'URL publique du '
            . 'timelapse de la galerie.';
    }

    public function inputSchema(): array
    {
        return $this->objectSchema(array_merge([
            'slug' => [
                'type' => 'string',
                'enum' => GalleryConfig::getAllowedSlugs(),
                'description' => 'Galerie : ffp3 (aquaponie), n3pp (serre), msp1 (potager).',
            ],
            'page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10000, 'default' => 1, 'description' => 'Page (1 = plus récentes).'],
            'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20, 'description' => 'Photos par page.'],
        ], $this->windowProperties('toute la galerie')), ['slug']);
    }

    public function call(array $arguments, McpContext $context): ToolResult
    {
        $slug = (string) $arguments['slug'];
        $entries = $this->gallery->entries($slug);
        if (isset($arguments['from']) || isset($arguments['to'])) {
            $window = TimeWindow::parse(
                isset($arguments['from']) ? (string) $arguments['from'] : null,
                isset($arguments['to']) ? (string) $arguments['to'] : null,
                86400,
                3650
            );
            $fromTs = $window->from->getTimestamp();
            $toTs = $window->to->getTimestamp();
            $entries = array_values(array_filter($entries, static fn (array $e): bool => $e['ts'] >= $fromTs && $e['ts'] <= $toTs));
        }
        $total = count($entries);
        $perPage = (int) ($arguments['per_page'] ?? 20);
        $page = (int) ($arguments['page'] ?? 1);
        $slice = array_slice($entries, ($page - 1) * $perPage, $perPage);

        return ToolResult::json([
            'slug' => $slug,
            'label' => GalleryConfig::getLabel($slug),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => (int) max(1, ceil($total / $perPage)),
            'newest' => $total > 0 ? date('Y-m-d H:i:s', $entries[0]['ts']) : null,
            'oldest' => $total > 0 ? date('Y-m-d H:i:s', $entries[$total - 1]['ts']) : null,
            'timelapse_path' => '/gallery/' . $slug . '/timelapse',
            'photos' => array_map(fn (array $e): array => [
                'filename' => $e['name'],
                'taken_at' => date('Y-m-d H:i:s', $e['ts']),
                'normalized' => $this->gallery->isNormalized($slug, $e['name']),
            ], $slice),
        ]);
    }
}

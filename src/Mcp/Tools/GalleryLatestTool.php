<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Config\GalleryConfig;
use App\Mcp\McpConfig;
use App\Mcp\McpContext;
use App\Mcp\Support\GalleryReader;
use App\Mcp\ToolException;
use App\Mcp\ToolResult;

/**
 * `iot_gallery_latest` — renvoie une photo (contenu MCP `image`) pour que le modèle la regarde.
 */
final class GalleryLatestTool extends AbstractTool
{
    public function __construct(McpConfig $config, private GalleryReader $gallery)
    {
        parent::__construct($config);
    }

    public function name(): string
    {
        return 'iot_gallery_latest';
    }

    public function title(): string
    {
        return 'Regarder une photo de galerie';
    }

    public function description(): string
    {
        return 'Renvoie la photo la plus récente d\'une galerie (ou celle désignée par « filename », obtenue via '
            . 'iot_gallery_list) sous forme d\'IMAGE JPEG redimensionnée (≤ 800 px, < 1 Mo) que tu peux examiner : état des '
            . 'plantes (flétrissement, jaunissement, croissance), niveau et couleur de l\'eau, présence des poissons, '
            . 'éclairage. Avec « previous », renvoie aussi la photo précédente (comparaison). Les photos de nuit peuvent être '
            . 'sombres. Lecture seule.';
    }

    public function inputSchema(): array
    {
        return $this->objectSchema([
            'slug' => [
                'type' => 'string',
                'enum' => GalleryConfig::getAllowedSlugs(),
                'description' => 'Galerie : ffp3 (aquaponie), n3pp (serre), msp1 (potager).',
            ],
            'filename' => [
                'type' => 'string',
                'maxLength' => 120,
                'pattern' => '^[a-zA-Z0-9_.-]+\.(jpg|jpeg|JPG|JPEG)$',
                'description' => 'Nom de fichier exact (défaut : la plus récente).',
            ],
            'previous' => ['type' => 'boolean', 'default' => false, 'description' => 'Joindre aussi la photo précédente.'],
            'max_edge' => ['type' => 'integer', 'minimum' => 200, 'maximum' => 800, 'default' => 800, 'description' => 'Plus grand côté en pixels.'],
        ], ['slug']);
    }

    public function call(array $arguments, McpContext $context): ToolResult
    {
        $slug = (string) $arguments['slug'];
        $entries = $this->gallery->entries($slug);
        if ($entries === []) {
            return ToolResult::json(['slug' => $slug, 'photo' => null, 'note' => 'Galerie vide.']);
        }
        $index = 0;
        if (isset($arguments['filename'])) {
            $index = array_search((string) $arguments['filename'], array_column($entries, 'name'), true);
            if ($index === false) {
                throw new ToolException('Photo introuvable dans la galerie ' . $slug . ' : ' . $arguments['filename'], 'not_found');
            }
        }
        $maxEdge = (int) ($arguments['max_edge'] ?? 800);
        $selection = [$entries[$index]];
        if (($arguments['previous'] ?? false) === true && isset($entries[$index + 1])) {
            $selection[] = $entries[$index + 1];
        }

        $photos = [];
        $images = [];
        foreach ($selection as $entry) {
            $path = $this->gallery->displayPath($slug, $entry['name']);
            $encoded = $path !== null ? $this->gallery->encodeForModel($path, $maxEdge) : null;
            $photos[] = [
                'filename' => $entry['name'],
                'taken_at' => date('Y-m-d H:i:s', $entry['ts']),
                'age_seconds' => max(0, time() - $entry['ts']),
                'normalized' => $this->gallery->isNormalized($slug, $entry['name']),
                'image' => $encoded !== null
                    ? ['width' => $encoded['width'], 'height' => $encoded['height'], 'bytes' => $encoded['bytes'], 'resized' => $encoded['resized']]
                    : null,
            ];
            if ($encoded !== null) {
                $images[] = $encoded;
            }
        }
        if ($images === []) {
            throw new ToolException('Impossible de lire ou de réduire la photo (fichier illisible ou trop lourd).', 'image_unavailable');
        }

        $result = ToolResult::json([
            'slug' => $slug,
            'label' => GalleryConfig::getLabel($slug),
            'photos' => $photos,
            'note' => count($images) > 1 ? 'Image 1 = photo demandée, image 2 = photo précédente.' : null,
        ]);
        foreach ($images as $image) {
            $result = $result->withImage($image['data'], $image['mime']);
        }

        return $result;
    }
}

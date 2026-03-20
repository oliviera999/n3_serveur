<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Configuration centralisée des galeries photo ESP32-CAM.
 * Source unique pour les slugs, labels, clés d'environnement et répertoires par défaut.
 */
final class GalleryConfig
{
    /** @var array<string, array{label: string, env_key: string, default_dir: string}> */
    private const GALLERIES = [
        'msp1' => [
            'label'       => 'Potager (MSP1)',
            'env_key'     => 'GALLERY_MSP1_DIR',
            'default_dir' => 'uploads/msp1',
        ],
        'n3pp' => [
            'label'       => 'Élevage (N3PP)',
            'env_key'     => 'GALLERY_N3PP_DIR',
            'default_dir' => 'uploads/n3pp',
        ],
        'ffp3' => [
            'label'       => 'Aquaponie (FFP3)',
            'env_key'     => 'GALLERY_FFP3_DIR',
            'default_dir' => 'uploads/ffp3',
        ],
    ];

    /** @return list<string> */
    public static function getAllowedSlugs(): array
    {
        return array_keys(self::GALLERIES);
    }

    public static function isValidSlug(string $slug): bool
    {
        return isset(self::GALLERIES[$slug]);
    }

    public static function getLabel(string $slug): string
    {
        return self::GALLERIES[$slug]['label'] ?? $slug;
    }

    /** @return array<string, string> slug => label */
    public static function getSlugLabels(): array
    {
        $labels = [];
        foreach (self::GALLERIES as $slug => $config) {
            $labels[$slug] = $config['label'];
        }
        return $labels;
    }

    public static function getEnvKey(string $slug): string
    {
        return self::GALLERIES[$slug]['env_key'] ?? '';
    }

    public static function getDefaultDir(string $slug): string
    {
        return self::GALLERIES[$slug]['default_dir'] ?? ('uploads/' . $slug);
    }

    /**
     * Résout le répertoire d'upload effectif (depuis $_ENV ou par défaut).
     */
    public static function resolveUploadDir(string $slug): string
    {
        $envKey = self::getEnvKey($slug);
        if ($envKey !== '' && !empty($_ENV[$envKey])) {
            return $_ENV[$envKey];
        }
        return self::getDefaultDir($slug);
    }
}

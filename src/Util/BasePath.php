<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Base path du front controller (ex. '' ou '/ffp3').
 * Défini une fois par requête dans index.php pour que les templates
 * puissent générer les URLs des assets correctement.
 */
final class BasePath
{
    private static string $path = '';

    public static function set(string $path): void
    {
        self::$path = rtrim($path, '/');
    }

    public static function get(): string
    {
        return self::$path;
    }

    /** Préfixe pour les assets : base_path + '/' ou '' */
    public static function assetsPrefix(): string
    {
        return self::$path === '' ? '' : self::$path . '/';
    }
}

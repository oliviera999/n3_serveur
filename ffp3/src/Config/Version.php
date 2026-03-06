<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Gestion de la version du projet
 * 
 * Version centralisée selon Semantic Versioning (MAJOR.MINOR.PATCH)
 */
class Version
{
    /**
     * Version actuelle du projet
     */
    private static ?string $version = null;

    /**
     * Récupère la version du projet
     * 
     * @return string Version (ex: "2.0.0")
     */
    public static function get(): string
    {
        if (self::$version === null) {
            $versionFile = __DIR__ . '/../../VERSION';
            if (file_exists($versionFile)) {
                self::$version = trim(file_get_contents($versionFile));
            } else {
                self::$version = '1.0.0'; // Fallback
            }
        }
        
        return self::$version;
    }

    /**
     * Récupère la version avec préfixe "v"
     * 
     * @return string Version (ex: "v2.0.0")
     */
    public static function getWithPrefix(): string
    {
        return 'v' . self::get();
    }

    /**
     * Récupère le nom complet de la release
     * 
     * @return string Nom complet (ex: "FFP3 Datas v2.0.0")
     */
    public static function getFullName(): string
    {
        return 'FFP3 Datas ' . self::getWithPrefix();
    }
}


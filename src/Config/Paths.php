<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Chemins centralisés du projet serveur.
 * Évite les dirname(__DIR__, N) dispersés dans les controllers.
 */
class Paths
{
    /** Racine du projet (dossier serveur/, parent de src/) */
    private static ?string $projectRoot = null;

    /**
     * Retourne la racine du projet serveur.
     * Peut être surchargée via la variable d'environnement UPLOADS_BASE_DIR.
     */
    public static function getProjectRoot(): string
    {
        if (self::$projectRoot !== null) {
            return self::$projectRoot;
        }
        $envDir = $_ENV['UPLOADS_BASE_DIR'] ?? null;
        if ($envDir !== null && $envDir !== '' && is_dir($envDir)) {
            self::$projectRoot = rtrim(realpath($envDir), DIRECTORY_SEPARATOR);
            return self::$projectRoot;
        }
        // Par défaut : depuis src/Config -> parent de src = racine serveur
        self::$projectRoot = rtrim(dirname(__DIR__, 2), DIRECTORY_SEPARATOR);
        return self::$projectRoot;
    }

    /**
     * Pour les tests : permet de surcharger la racine.
     */
    public static function setProjectRoot(?string $path): void
    {
        self::$projectRoot = $path;
    }
}

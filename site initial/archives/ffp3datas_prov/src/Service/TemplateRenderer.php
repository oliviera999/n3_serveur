<?php

namespace App\Service;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class TemplateRenderer
{
    private static ?Environment $twig = null;

    /**
     * Initialise Twig s'il ne l'est pas encore et retourne l'instance.
     */
    private static function getTwig(): Environment
    {
        if (self::$twig === null) {
            $loader = new FilesystemLoader(dirname(__DIR__, 2) . '/templates');
            self::$twig = new Environment($loader, [
                'cache' => false, // à activer en prod
                'autoescape' => 'html',
            ]);
        }
        return self::$twig;
    }

    /**
     * Rend un template Twig et retourne la chaîne HTML.
     *
     * @param string $template Nom de fichier (ex: 'dashboard.twig')
     * @param array<string,mixed> $context Variables passées au template
     */
    public static function render(string $template, array $context = []): string
    {
        return self::getTwig()->render($template, $context);
    }
} 
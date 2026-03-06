<?php

namespace App\Service;

use App\Security\CsrfService;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class TemplateRenderer
{
    private Environment $twig;
    private ?CsrfService $csrfService;

    public function __construct(
        string $templatesPath,
        bool $useCache = true,
        ?CsrfService $csrfService = null
    ) {
        $this->csrfService = $csrfService;
        
        $loader = new FilesystemLoader($templatesPath);

        $cacheConfig = false;
        if ($useCache) {
            $cacheDir = dirname(__DIR__, 2) . '/var/cache/twig';
            if (!is_dir($cacheDir)) {
                @mkdir($cacheDir, 0755, true);
            }
            $cacheConfig = $cacheDir;
        }

        $this->twig = new Environment($loader, [
            'cache' => $cacheConfig,
            'autoescape' => 'html',
        ]);
    }

    /**
     * Rend un template Twig et retourne la chaîne HTML.
     *
     * @param string $template Nom de fichier (ex: 'dashboard.twig')
     * @param array<string,mixed> $context Variables passées au template
     */
    public function render(string $template, array $context = []): string
    {
        $context['base_path'] = $GLOBALS['base_path'] ?? '';
        // #region agent log
        if (
            \function_exists('n3DebugLog')
            && \in_array($template, ['msp1_data.twig', 'n3pp_data.twig', 'home.twig'], true)
        ) {
            \n3DebugLog(
                'H5',
                'serveur/src/Service/TemplateRenderer.php:49',
                'Rendu template public',
                [
                    'template' => $template,
                    'base_path' => $context['base_path'],
                    'page_title' => $context['page_title'] ?? null,
                ]
            );
        }
        // #endregion
        if ($this->csrfService !== null) {
            $context['csrf_token'] = $this->csrfService->getToken();
            $context['csrf_field'] = $this->csrfService->getHiddenField();
        }
        return $this->twig->render($template, $context);
    }
}

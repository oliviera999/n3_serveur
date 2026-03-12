<?php

namespace App\Service;

use App\Config\Version;
use App\Security\AuthService;
use App\Security\CsrfService;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class TemplateRenderer
{
    private Environment $twig;
    private ?CsrfService $csrfService;
    private ?AuthService $authService;

    public function __construct(
        string $templatesPath,
        bool $useCache = true,
        ?CsrfService $csrfService = null,
        ?AuthService $authService = null
    ) {
        $this->csrfService = $csrfService;
        $this->authService = $authService;
        
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
        $context['version'] = $context['version'] ?? Version::getWithPrefix();
        if ($this->authService !== null) {
            $queryParams = $_GET ?? [];
            $context['is_admin'] = $this->authService->isAuthenticated()
                || $this->authService->isAuthenticatedByToken($queryParams);
        } else {
            $context['is_admin'] = false;
        }
        if ($this->csrfService !== null) {
            $context['csrf_token'] = $this->csrfService->getToken();
            $context['csrf_field'] = $this->csrfService->getHiddenField();
        }
        return $this->twig->render($template, $context);
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\Version;
use App\Security\AuthService;
use App\Security\CsrfService;
use App\Util\DisplayTime;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;

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

        // Filtre d'affichage en heure de Casablanca (heure locale réelle du projet).
        // Les horodatages sont stockés en heure serveur (APP_TIMEZONE) ; ce filtre les
        // convertit pour rester cohérent avec les graphiques et stats temps réel.
        $this->twig->addFilter(new TwigFilter(
            'localdt',
            static fn (mixed $value, string $format = 'd/m/Y H:i:s'): string => DisplayTime::toDisplay(
                is_string($value) || $value instanceof \DateTimeInterface ? $value : null,
                $format
            )
        ));
    }

    /**
     * Rend un template Twig et retourne la chaîne HTML.
     *
     * @param string $template Nom de fichier (ex: 'dashboard.twig')
     * @param array<string,mixed> $context Variables passées au template
     */
    public function render(string $template, array $context = []): string
    {
        $bp = $GLOBALS['base_path'] ?? '';
        $context['base_path'] = ($bp !== '' && $bp !== '/') ? '/' . trim($bp, '/') : $bp;
        $context['version'] = $context['version'] ?? Version::getWithPrefix();
        $context['footer_config'] = $this->buildFooterConfig($context);
        if ($this->authService !== null) {
            try {
                $queryParams = $_GET ?? [];
                $isAuth = $this->authService->isAuthenticated()
                    || $this->authService->isAuthenticatedByToken($queryParams);
                $context['is_admin'] = $isAuth && $this->authService->canAccessControl();
                $context['user_role'] = $this->authService->getCurrentRole();
                $context['can_manage_users'] = $isAuth && $this->authService->canManageUsers();
                $context['can_access_control'] = $isAuth && $this->authService->canAccessControl();
                $context['current_username'] = $this->authService->getCurrentUser();
            } catch (\Throwable) {
                $context['is_admin'] = false;
                $context['user_role'] = null;
                $context['can_manage_users'] = false;
                $context['can_access_control'] = false;
                $context['current_username'] = null;
            }
        } else {
            $context['is_admin'] = false;
            $context['user_role'] = null;
            $context['can_manage_users'] = false;
            $context['can_access_control'] = false;
            $context['current_username'] = null;
        }
        if ($this->csrfService !== null) {
            $context['csrf_token'] = $this->csrfService->getToken();
            $context['csrf_field'] = $this->csrfService->getHiddenField();
        }
        return $this->twig->render($template, $context);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, string>
     */
    private function buildFooterConfig(array $context): array
    {
        $dataConfig = [];
        if (isset($context['data_config']) && is_array($context['data_config'])) {
            $dataConfig = $context['data_config'];
        }

        $environment = '';
        if (isset($context['environment']) && is_string($context['environment'])) {
            $environment = $context['environment'];
        }

        $tableLabel = '';
        if (isset($dataConfig['table_label']) && is_string($dataConfig['table_label'])) {
            $tableLabel = $dataConfig['table_label'];
        } elseif (isset($context['data_table']) && is_string($context['data_table'])) {
            $tableLabel = 'Table ' . $context['data_table'];
        }

        $firmwareVersion = '';
        if (isset($context['firmware_version']) && is_string($context['firmware_version'])) {
            $firmwareVersion = $context['firmware_version'];
        }

        $footerText = '';
        if (isset($context['footer_text']) && is_string($context['footer_text'])) {
            $footerText = $context['footer_text'];
        } elseif (isset($dataConfig['footer_text']) && is_string($dataConfig['footer_text'])) {
            $footerText = $dataConfig['footer_text'];
        }

        $basePath = '';
        if (isset($context['base_path']) && is_string($context['base_path'])) {
            $basePath = $context['base_path'];
        }

        $version = '';
        if (isset($context['version']) && is_string($context['version'])) {
            $version = $context['version'];
        }

        return [
            'environment' => $environment,
            'table_label' => $tableLabel,
            'version' => $version,
            'firmware_version' => $firmwareVersion,
            'footer_text' => $footerText,
            'base_path' => $basePath,
        ];
    }
}

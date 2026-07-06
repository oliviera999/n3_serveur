<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\Version;
use App\Repository\NavPageRepository;
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
    private ?NavPageRepository $navPageRepository;

    public function __construct(
        string $templatesPath,
        bool $useCache = true,
        ?CsrfService $csrfService = null,
        ?AuthService $authService = null,
        ?NavPageRepository $navPageRepository = null
    ) {
        $this->csrfService = $csrfService;
        $this->authService = $authService;
        $this->navPageRepository = $navPageRepository;

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
        // Pages du menu de navigation (état serveur global partagé par tous les
        // visiteurs). Injecté sur chaque page pour un menu cohérent côté serveur.
        if (!isset($context['nav_pages'])) {
            $context['nav_pages'] = $this->loadNavPages();
        }
        return $this->twig->render($template, $context);
    }

    /**
     * Pages actives du menu, en tolérant l'absence de BDD (menu vide plutôt que 500).
     *
     * @return list<array{key: string, label: string, url: string}>
     */
    private function loadNavPages(): array
    {
        if ($this->navPageRepository === null) {
            return [];
        }
        try {
            $pages = $this->navPageRepository->getActivePages();
        } catch (\Throwable) {
            return [];
        }

        // Certaines pages du menu sont soumises à permission : le lien
        // « Utilisateurs » (`admin-users`) n'apparaît que pour les gestionnaires
        // de comptes, même s'il est actif dans navPages.
        $canManageUsers = false;
        if ($this->authService !== null) {
            try {
                $canManageUsers = $this->authService->isAuthenticated()
                    && $this->authService->canManageUsers();
            } catch (\Throwable) {
                $canManageUsers = false;
            }
        }
        if (!$canManageUsers) {
            $pages = array_values(array_filter(
                $pages,
                static fn (array $page): bool => $page['key'] !== 'admin-users'
            ));
        }

        return $pages;
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

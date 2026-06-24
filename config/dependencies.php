<?php

declare(strict_types=1);

use App\Config\Database;
use Psr\Container\ContainerInterface;

/*
 * Définitions explicites du container PHP-DI.
 *
 * PHP-DI a l'AUTOWIRING actif par défaut : toute classe dont le constructeur ne
 * prend que des dépendances typées par des classes/interfaces elles-mêmes
 * résolvables est instanciée automatiquement. Inutile donc de la déclarer ici.
 *
 * Ne restent ci-dessous que les entrées qui apportent quelque chose que
 * l'autowiring ne sait pas faire seul :
 *   - construction d'une ressource externe (PDO via Database) ;
 *   - lecture de scalaires / variables d'environnement / fichiers de config.
 *
 * ➜ Pour ajouter une nouvelle classe « pure » (constructeur n'attendant que des
 *   services/repositories), AUCUNE entrée n'est nécessaire ici : l'autowiring
 *   s'en charge (y compris en mode compilé en production).
 */
return [
    // ====================================================================
    // DATABASE CONNECTION (Singleton) — fabrique : connexion PDO centralisée
    // ====================================================================
    PDO::class => function (ContainerInterface $c): PDO {
        return Database::getConnection();
    },

    // ====================================================================
    // SÉCURITÉ — entrées lisant des scalaires / l'environnement / la config
    // ====================================================================

    // Lit le fichier de configuration des routes (argument array non autowirable).
    \App\Security\RoleAccessService::class => function (ContainerInterface $c): \App\Security\RoleAccessService {
        $routesConfigPath = __DIR__ . '/routes_config.php';
        $routesConfig = is_file($routesConfigPath) ? require $routesConfigPath : [];

        return new \App\Security\RoleAccessService($routesConfig);
    },

    // Répertoire de stockage optionnel lu depuis l'environnement (?string).
    \App\Security\RateLimiter::class => function (ContainerInterface $c): \App\Security\RateLimiter {
        $dir = getenv('RATE_LIMIT_DIR');

        return new \App\Security\RateLimiter(is_string($dir) && $dir !== '' ? $dir : null);
    },

    // Anti-brute-force login par IP : scope + seuils lus depuis l'environnement.
    \App\Middleware\RateLimitMiddleware::class => function (ContainerInterface $c): \App\Middleware\RateLimitMiddleware {
        $max = (int) (getenv('LOGIN_RATE_LIMIT_MAX') ?: 20);
        $window = (int) (getenv('LOGIN_RATE_LIMIT_WINDOW') ?: 600);

        return new \App\Middleware\RateLimitMiddleware(
            $c->get(\App\Security\RateLimiter::class),
            'login',
            $max,
            $window
        );
    },

    // ====================================================================
    // RENDU — chemin templates (string) + flag cache déduit de l'environnement
    // ====================================================================
    \App\Service\TemplateRenderer::class => function (ContainerInterface $c): \App\Service\TemplateRenderer {
        $templatesPath = __DIR__ . '/../templates';
        $resolved = realpath($templatesPath);

        return new \App\Service\TemplateRenderer(
            $resolved !== false ? $resolved : $templatesPath,
            ($_ENV['ENV'] ?? 'prod') === 'prod',
            $c->get(\App\Security\CsrfService::class),
            $c->get(\App\Security\AuthService::class)
        );
    },

    // ====================================================================
    // Notifications — politique par famille (BDD pages contrôle) avec repli .env global.
    \App\Repository\NotificationPolicyRepository::class => function (ContainerInterface $c): \App\Repository\NotificationPolicyRepository {
        return new \App\Repository\NotificationPolicyRepository(
            $c->get(\PDO::class)
        );
    },

    \App\Notification\NotificationPolicyResolver::class => function (ContainerInterface $c): \App\Notification\NotificationPolicyResolver {
        return \App\Notification\NotificationPolicyResolver::fromEnv(
            $c->get(\App\Repository\NotificationPolicyRepository::class)
        );
    },

    \App\Service\NotificationPolicySaveService::class => function (ContainerInterface $c): \App\Service\NotificationPolicySaveService {
        return new \App\Service\NotificationPolicySaveService(
            $c->get(\App\Repository\NotificationPolicyRepository::class)
        );
    },

    \App\Service\NotificationService::class => function (ContainerInterface $c): \App\Service\NotificationService {
        $logger = $c->get(\App\Service\LogService::class);

        return new \App\Service\NotificationService(
            $logger,
            null,
            new \App\Notification\AlertThrottler($logger),
            \App\Notification\MailTransportFactory::fromEnv($logger),
            new \App\Notification\EmailRenderer(),
            new \App\Notification\NotificationDigest($logger),
            $c->get(\App\Notification\NotificationPolicyResolver::class)
        );
    },

    // ====================================================================
    // COMMANDS (CRON) — dépendances OBLIGATOIREMENT explicites.
    // Leurs constructeurs ont des paramètres TOUS optionnels (?Type = null) :
    // l'autowiring PHP-DI laisserait ces paramètres à null, déclenchant le
    // fallback `Database::getConnection()` (connexion MySQL réelle) au build.
    // On câble donc explicitement les services pour éviter ce fallback.
    // ====================================================================
    \App\Command\RestartPumpCommand::class => function (ContainerInterface $c): \App\Command\RestartPumpCommand {
        return new \App\Command\RestartPumpCommand(
            $c->get(\App\Service\PumpService::class),
            $c->get(\App\Service\LogService::class)
        );
    },

    \App\Command\CronOrchestrator::class => function (ContainerInterface $c): \App\Command\CronOrchestrator {
        return new \App\Command\CronOrchestrator(
            $c->get(\App\Service\LogService::class),
            $c->get(\App\Service\SensorDataService::class),
            $c->get(\App\Service\PumpService::class),
            $c->get(\App\Service\SensorStatisticsService::class),
            $c->get(\App\Service\NotificationService::class),
            $c->get(\App\Repository\SensorReadRepository::class),
            $c->get(\App\Service\SystemHealthService::class),
            $c->get(\App\Service\DeviceHealthService::class),
            $c->get(\App\Command\RestartPumpCommand::class)
        );
    },
];

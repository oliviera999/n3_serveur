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
            $c->get(\App\Security\AuthService::class),
            $c->get(\App\Repository\NavPageRepository::class)
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
            $c->get(\App\Repository\NotificationPolicyRepository::class),
            $c->get(\App\Service\OperationalSettingsService::class)
        );
    },

    \App\Service\LogService::class => function (ContainerInterface $c): \App\Service\LogService {
        return new \App\Service\LogService(
            $c->get(\App\Service\OperationalSettingsService::class)
        );
    },

    \App\Service\DeviceHealthService::class => function (ContainerInterface $c): \App\Service\DeviceHealthService {
        return new \App\Service\DeviceHealthService(
            $c->get(\App\Repository\HeartbeatMonitorRepository::class),
            $c->get(\App\Service\NotificationService::class),
            $c->get(\App\Service\LogService::class),
            null,
            null,
            $c->get(\App\Service\OfflineThresholdResolver::class),
            $c->get(\App\Service\OperationalSettingsService::class),
            // Contre-preuve « le module envoie des données » : tables de mesures par
            // famille (lecture paresseuse — aucun repo instancié si l'alerte n'est pas
            // envisagée). Un heartbeat perdu ne déclenche plus d'alerte « silencieux ».
            [
                'FFP3' => static fn (): ?string => $c->get(\App\Repository\SensorReadRepository::class)->getLastReadingDate(),
                'N3PP' => static fn (): ?string => $c->get(\App\Repository\N3ppSensorRepository::class)->getLastReadingDate(),
                'MSP1' => static fn (): ?string => $c->get(\App\Repository\MspSensorRepository::class)->getLastReadingDate(),
            ]
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
            logger: $c->get(\App\Service\LogService::class),
            sensorDataService: $c->get(\App\Service\SensorDataService::class),
            pumpService: $c->get(\App\Service\PumpService::class),
            statsService: $c->get(\App\Service\SensorStatisticsService::class),
            notifier: $c->get(\App\Service\NotificationService::class),
            sensorReadRepo: $c->get(\App\Repository\SensorReadRepository::class),
            healthService: $c->get(\App\Service\SystemHealthService::class),
            deviceHealthService: $c->get(\App\Service\DeviceHealthService::class),
            restartPumpCommand: $c->get(\App\Command\RestartPumpCommand::class),
            operationalSettings: $c->get(\App\Service\OperationalSettingsService::class),
        );
    },
];

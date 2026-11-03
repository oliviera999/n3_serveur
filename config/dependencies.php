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

    // Machine à états « disponibilité » : UN e-mail quand un appareil se tait, UN quand il
    // réémet, aucun rappel entre les deux. Partagée par les deux supervisions hors-ligne
    // (DeviceHealthService = heartbeat, SystemHealthService = flux de données) afin qu'une
    // même panne ne produise qu'un seul e-mail. État persisté sous `var/cache/`.
    \App\Service\Availability\AvailabilityNotifier::class => function (ContainerInterface $c): \App\Service\Availability\AvailabilityNotifier {
        return new \App\Service\Availability\AvailabilityNotifier(
            $c->get(\App\Service\NotificationService::class),
            $c->get(\App\Service\LogService::class),
            new \App\Util\JsonFileStore(dirname(__DIR__) . '/var/cache/availability_state.json')
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
            ],
            // Sans elle, une panne prolongée rappellerait son alerte à chaque passage horaire.
            $c->get(\App\Service\Availability\AvailabilityNotifier::class)
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
            // Connexion du container (SQLite en test) : sans elle, `$needsDatabase` est
            // faux — tous les services ci-dessus étant injectés — et l'orchestrateur
            // laissait à null OutputRepository, OfflineThresholdResolver et les trois
            // services d'alertes dérivées. Conséquences en production : seuils BDD
            // (GPIO 102/129) ignorés au profit de `.env`, seuil hors-ligne figé à 3600 s,
            // et surtout AUCUNE alerte dérivée émise (trop-plein, chauffage, remplissage,
            // sol sec, batterie, redémarrage, météo).
            pdo: $c->get(\PDO::class),
            galleryNormalizeService: $c->get(\App\Service\GalleryNormalizeService::class),
            mcpOAuthPurge: $c->get(\App\Command\McpOAuthPurgeCommand::class),
            // Rapport de santé régulier (no-op tant que MCP_HEALTH_REPORT_ENABLED=false).
            healthReport: $c->get(\App\Command\HealthReportCommand::class),
        );
    },

    \App\Service\GalleryNormalizeService::class => \DI\autowire()
        ->constructorParameter('operationalSettings', \DI\get(\App\Service\OperationalSettingsService::class)),

    // ====================================================================
    // Dépendances OPTIONNELLES (`?Type $x = null`) — jamais autowirées.
    // PHP-DI ignore délibérément les paramètres optionnels
    // (ReflectionBasedAutowiring : « Skip optional parameters »), donc toute
    // dépendance déclarée avec une valeur par défaut reste null sans câblage
    // explicite. Pour les services ci-dessous, ce null n'est pas neutre : il
    // débranche silencieusement la lecture des réglages pilotés en BDD
    // (`serverSettings` via OperationalSettingsService, seuils de la table
    // outputs) et fait retomber le code sur `.env`.
    // `DI\autowire()->constructorParameter()` conserve l'autowiring pour tout
    // le reste du constructeur et ne renseigne que le paramètre optionnel.
    // ====================================================================
    \App\Service\SystemHealthService::class => \DI\autowire()
        ->constructorParameter('outputRepo', \DI\get(\App\Repository\OutputRepository::class))
        ->constructorParameter('operationalSettings', \DI\get(\App\Service\OperationalSettingsService::class))
        ->constructorParameter('availability', \DI\get(\App\Service\Availability\AvailabilityNotifier::class)),

    \App\Repository\SensorReadRepository::class => \DI\autowire()
        ->constructorParameter('operationalSettings', \DI\get(\App\Service\OperationalSettingsService::class)),

    \App\Repository\GallerySyncRepository::class => \DI\autowire()
        ->constructorParameter('operationalSettings', \DI\get(\App\Service\OperationalSettingsService::class)),

    \App\Service\SensorDataService::class => \DI\autowire()
        ->constructorParameter('operationalSettings', \DI\get(\App\Service\OperationalSettingsService::class)),

    \App\Service\HmacAuditLogger::class => \DI\autowire()
        ->constructorParameter('operationalSettings', \DI\get(\App\Service\OperationalSettingsService::class)),

    \App\Controller\Ffp3\HeartbeatController::class => \DI\autowire()
        ->constructorParameter('operationalSettings', \DI\get(\App\Service\OperationalSettingsService::class)),

    \App\Controller\Gallery\GalleryUploadController::class => \DI\autowire()
        ->constructorParameter('operationalSettings', \DI\get(\App\Service\OperationalSettingsService::class)),

    \App\Controller\Msp\MspOutputController::class => \DI\autowire()
        ->constructorParameter('operationalSettings', \DI\get(\App\Service\OperationalSettingsService::class))
        ->constructorParameter('displayPresets', \DI\get(\App\Service\DisplayPresetService::class)),

    \App\Controller\N3pp\N3ppOutputController::class => \DI\autowire()
        ->constructorParameter('operationalSettings', \DI\get(\App\Service\OperationalSettingsService::class))
        ->constructorParameter('displayPresets', \DI\get(\App\Service\DisplayPresetService::class)),

    // Les contrôleurs construisent FirmwareStateCompat eux-mêmes (`new`), mais on câble
    // aussi l'entrée du container : un futur autowiring hériterait sinon d'un service muet.
    \App\Service\FirmwareStateCompat::class => \DI\autowire()
        ->constructorParameter('settings', \DI\get(\App\Service\OperationalSettingsService::class)),

    // Live n3pp/msp : le réglage alimente FirmwareStateCompat (FIRMWARE_FLAT_STATE_MODE),
    // construit dans le constructeur — sans lui, la manette BDD reste sans effet.
    \App\Controller\Msp\MspRealtimeApiController::class => \DI\autowire()
        ->constructorParameter('operationalSettings', \DI\get(\App\Service\OperationalSettingsService::class)),

    \App\Controller\N3pp\N3ppRealtimeApiController::class => \DI\autowire()
        ->constructorParameter('operationalSettings', \DI\get(\App\Service\OperationalSettingsService::class)),

    // Heartbeat n3pp/msp : sans câblage, l'audit HMAC n'enregistrait rien et le mode
    // strict retombait sur `.env` (le réglage BDD de supervision était ignoré), alors
    // que le heartbeat FFP3 — dont la dépendance d'audit est obligatoire — l'appliquait.
    \App\Controller\Msp\MspHeartbeatController::class => \DI\autowire()
        ->constructorParameter('hmacAuditLogger', \DI\get(\App\Service\HmacAuditLogger::class))
        ->constructorParameter('hmacPolicyService', \DI\get(\App\Service\HmacPolicyService::class))
        ->constructorParameter('operationalSettings', \DI\get(\App\Service\OperationalSettingsService::class)),

    \App\Controller\N3pp\N3ppHeartbeatController::class => \DI\autowire()
        ->constructorParameter('hmacAuditLogger', \DI\get(\App\Service\HmacAuditLogger::class))
        ->constructorParameter('hmacPolicyService', \DI\get(\App\Service\HmacPolicyService::class))
        ->constructorParameter('operationalSettings', \DI\get(\App\Service\OperationalSettingsService::class)),

    // POST banc énergie (INA226) : audit HMAC + mode strict / nonce + réglages opérationnels
    // (fenêtre SIG_VALID_WINDOW, rate-limit firmware) pilotés en BDD — câblage explicite,
    // les paramètres nullables ne doivent jamais retomber silencieusement à null.
    \App\Controller\Energie\EnergiePostDataController::class => \DI\autowire()
        ->constructorParameter('hmacAuditLogger', \DI\get(\App\Service\HmacAuditLogger::class))
        ->constructorParameter('hmacPolicyService', \DI\get(\App\Service\HmacPolicyService::class))
        ->constructorParameter('operationalSettings', \DI\get(\App\Service\OperationalSettingsService::class)),

    // --------------------------------------------------------------------
    // Authentification et rendu — même défaut, conséquences non plus sur la
    // configuration mais sur le contrôle d'accès.
    // --------------------------------------------------------------------

    // Sans UserRepository, `authenticateFromDatabase()` retourne toujours null :
    // les comptes de la table `n3_users` ne pouvaient pas se connecter (seul le
    // couple ADMIN_USERNAME / ADMIN_PASSWORD_HASH du `.env` fonctionnait) et
    // `last_login` n'était jamais mis à jour.
    \App\Security\AuthService::class => \DI\autowire()
        ->constructorParameter('userRepository', \DI\get(\App\Repository\UserRepository::class)),

    // Sans RoleAccessService, `hasRoleAccess()` retournait true pour TOUT chemin :
    // le filtrage par rôle (role_requirements de routes_config.php) était inopérant.
    // Le contrôle intervient après l'authentification, et l'auth `.env` donne
    // ROLE_ADMIN : rétablir le filtre ne peut pas verrouiller une session existante.
    // TemplateRenderer : page 403 rendue au lieu d'une réponse brute.
    \App\Middleware\AuthGuardMiddleware::class => \DI\autowire()
        ->constructorParameter('roleAccessService', \DI\get(\App\Security\RoleAccessService::class))
        ->constructorParameter('templateRenderer', \DI\get(\App\Service\TemplateRenderer::class)),

    // Pages d'erreur : sans renderer, réponse brute au lieu du template.
    \App\Middleware\ErrorHandlerMiddleware::class => \DI\autowire()
        ->constructorParameter('renderer', \DI\get(\App\Service\TemplateRenderer::class)),

    \App\Controller\Ffp3\CacheController::class => \DI\autowire()
        ->constructorParameter('renderer', \DI\get(\App\Service\TemplateRenderer::class)),

    // ====================================================================
    // CONNECTEUR MCP (Claude) + serveur OAuth embarqué — docs/MCP.md
    // ====================================================================
    \App\Mcp\McpConfig::class => static fn (): \App\Mcp\McpConfig => \App\Mcp\McpConfig::fromEnv(),

    // Connexion des OUTILS DE LECTURE : utilisateur SQL en lecture seule si DB_MCP_USER /
    // DB_MCP_PASS sont fournis (défense en profondeur), sinon la connexion principale.
    // Dans les deux cas les outils de lecture tournent en transaction READ ONLY annulée.
    'mcp.read_pdo' => static fn (ContainerInterface $c): PDO => Database::getReadOnlyConnection() ?? $c->get(PDO::class),

    \App\Repository\McpReadRepository::class => static fn (ContainerInterface $c): \App\Repository\McpReadRepository
        => new \App\Repository\McpReadRepository($c->get('mcp.read_pdo')),

    \App\Mcp\Support\ReadOnlyTransaction::class => static fn (ContainerInterface $c): \App\Mcp\Support\ReadOnlyTransaction
        => new \App\Mcp\Support\ReadOnlyTransaction($c->get('mcp.read_pdo'), $c->get(PDO::class)),

    \App\OAuth\HttpFetcher::class => \DI\autowire(\App\OAuth\CurlHttpFetcher::class),

    // Outils exposés par le serveur MCP (ordre = ordre de tools/list ; noms stables).
    \App\Mcp\ToolRegistry::class => static fn (ContainerInterface $c): \App\Mcp\ToolRegistry => new \App\Mcp\ToolRegistry(
        array_map(static fn (string $class): \App\Mcp\Tool => $c->get($class), [
            \App\Mcp\Tools\OverviewTool::class,
            \App\Mcp\Tools\ServerHealthTool::class,
            \App\Mcp\Tools\DescribeTool::class,
            \App\Mcp\Tools\LatestTool::class,
            \App\Mcp\Tools\SeriesTool::class,
            \App\Mcp\Tools\StatsTool::class,
            \App\Mcp\Tools\TidesTool::class,
            \App\Mcp\Tools\EnergyTool::class,
            \App\Mcp\Tools\PglStatsTool::class,
            \App\Mcp\Tools\OutputsStateTool::class,
            \App\Mcp\Tools\AlertsTool::class,
            \App\Mcp\Tools\ContractCheckTool::class,
            \App\Mcp\Tools\GalleryListTool::class,
            \App\Mcp\Tools\GalleryLatestTool::class,
            \App\Mcp\Tools\PlanOutputChangeTool::class,
            \App\Mcp\Tools\ApplyPlanTool::class,
            // Exploitation : mémoire persistante, santé du site, fichiers (docs/MCP.md §§ 8-10).
            \App\Mcp\Tools\RecallTool::class,
            \App\Mcp\Tools\RememberTool::class,
            \App\Mcp\Tools\ForgetTool::class,
            \App\Mcp\Tools\HealthReportTool::class,
            \App\Mcp\Tools\HealthHistoryTool::class,
            \App\Mcp\Tools\CronStatusTool::class,
            \App\Mcp\Tools\ListFilesTool::class,
            \App\Mcp\Tools\ReadFileTool::class,
            \App\Mcp\Tools\GrepFilesTool::class,
        ]),
        $c->get(\App\Mcp\McpConfig::class)
    ),

    // Limiteurs dédiés (portées distinctes du login web).
    'mcp.rate_limit' => static function (ContainerInterface $c): \App\Middleware\RateLimitMiddleware {
        $config = $c->get(\App\Mcp\McpConfig::class);

        return new \App\Middleware\RateLimitMiddleware(
            $c->get(\App\Security\RateLimiter::class),
            'mcp',
            $config->rateLimitMax(),
            $config->rateLimitWindow()
        );
    },
    'mcp.oauth_rate_limit' => static fn (ContainerInterface $c): \App\Middleware\RateLimitMiddleware => new \App\Middleware\RateLimitMiddleware(
        $c->get(\App\Security\RateLimiter::class),
        'mcp_oauth',
        60,
        600
    ),

    // Purge CRON des jetons/codes/plans expirés (sous-commande de CronOrchestrator).
    \App\Command\McpOAuthPurgeCommand::class => \DI\autowire(),

    // --------------------------------------------------------------------
    // Exploitation du connecteur : santé du site, état du CRON, fichiers.
    // --------------------------------------------------------------------
    // Journaux : LOG_FILE_PATH (relatif = racine du dépôt pour le CRON, public/ pour le web).
    \App\Mcp\Health\LogLocator::class => static fn (): \App\Mcp\Health\LogLocator
        => new \App\Mcp\Health\LogLocator(dirname(__DIR__)),

    // Cadences attendues des sous-tâches, lues comme le CRON (réglage BDD > .env > défaut).
    \App\Mcp\Health\CronStatusReader::class => static function (ContainerInterface $c): \App\Mcp\Health\CronStatusReader {
        $settings = $c->get(\App\Service\OperationalSettingsService::class);
        $config = $c->get(\App\Mcp\McpConfig::class);

        return new \App\Mcp\Health\CronStatusReader(
            dirname(__DIR__),
            $c->get(\App\Mcp\Health\LogLocator::class),
            $settings->int('CRON_HOURLY_INTERVAL_SECONDS', 3600),
            $settings->int('GALLERY_NORMALIZE_INTERVAL_SECONDS', 600),
            $config->isHealthReportEnabled() ? $config->healthReportIntervalSeconds() : null,
        );
    },

    \App\Mcp\Health\CertificateProbe::class => \DI\autowire(\App\Mcp\Health\StreamCertificateProbe::class),

    // Galeries : même racine que les pages galerie et iot_server_health (UPLOADS_BASE_DIR).
    \App\Mcp\Health\HealthReportBuilder::class => \DI\autowire()
        ->constructorParameter('projectRoot', \DI\factory(static fn (): string => \App\Config\Paths::getProjectRoot())),

    \App\Mcp\Files\FileRoots::class => static fn (ContainerInterface $c): \App\Mcp\Files\FileRoots => new \App\Mcp\Files\FileRoots(
        dirname(__DIR__),
        $c->get(\App\Mcp\Health\LogLocator::class),
        $c->get(\App\Mcp\McpConfig::class)->fileRoots()
    ),

    \App\Mcp\Files\SafeFileReader::class => static fn (ContainerInterface $c): \App\Mcp\Files\SafeFileReader => new \App\Mcp\Files\SafeFileReader(
        $c->get(\App\Mcp\Files\FileRoots::class),
        $c->get(\App\Mcp\McpConfig::class)->fileMaxBytes()
    ),

    // GET /health/report.json : 30 requêtes / 10 min / IP (jeton deviné = 401, débit borné).
    'mcp.health_rate_limit' => static fn (ContainerInterface $c): \App\Middleware\RateLimitMiddleware => new \App\Middleware\RateLimitMiddleware(
        $c->get(\App\Security\RateLimiter::class),
        'mcp_health',
        30,
        600
    ),
];

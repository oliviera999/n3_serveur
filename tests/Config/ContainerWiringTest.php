<?php

declare(strict_types=1);

namespace Tests\Config;

use DI\ContainerBuilder;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Filet de sécurité du câblage DI.
 *
 * Depuis la purge des closures de fabrique redondantes (v5.3.4), la quasi-totalité
 * des contrôleurs / services / repositories sont instanciés par AUTOWIRING.
 * Ce test construit le VRAI container (mêmes définitions que
 * {@see \config/container.php}, mais sans compilation pour rester testable) et
 * résout un échantillon représentatif : tous les contrôleurs déclarés dans
 * public/index.php + les services, repositories, middlewares et commandes clés.
 *
 * Si une dépendance n'est plus résolvable par l'autowiring (ajout d'un paramètre
 * scalaire non couvert, suppression abusive d'une définition explicite…), ce test
 * échoue immédiatement — sans nécessiter de base de données réelle (PDO est
 * remplacé par une instance SQLite en mémoire).
 */
final class ContainerWiringTest extends TestCase
{
    private static function buildContainer(): ContainerInterface
    {
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        // Pas de compilation : on teste la résolution dynamique, sans cache de prod.
        $builder->addDefinitions(__DIR__ . '/../../config/dependencies.php');
        // Surcharge PDO : évite toute connexion MySQL réelle pendant les tests.
        $builder->addDefinitions([
            PDO::class => static function (): PDO {
                return new PDO('sqlite::memory:');
            },
        ]);

        return $builder->build();
    }

    /**
     * @return list<class-string>
     */
    private static function controllers(): array
    {
        return [
            \App\Controller\AuthController::class,
            \App\Controller\HomeController::class,
            \App\Controller\GlossaireController::class,
            \App\Controller\LocalDataPagesController::class,
            \App\Controller\SupervisionController::class,
            \App\Controller\Admin\UserAdminController::class,
            \App\Controller\Ffp3\AquaponieController::class,
            \App\Controller\Ffp3\AquaponieDescriptionController::class,
            \App\Controller\Ffp3\DashboardController::class,
            \App\Controller\Ffp3\ExportController::class,
            \App\Controller\Ffp3\TideStatsController::class,
            \App\Controller\Ffp3\RealtimeApiController::class,
            \App\Controller\Ffp3\OutputController::class,
            \App\Controller\Ffp3\PostDataController::class,
            \App\Controller\Ffp3\HeartbeatController::class,
            \App\Controller\Ffp3\CacheController::class,
            \App\Controller\Msp\MspDataController::class,
            \App\Controller\Msp\MspDescriptionController::class,
            \App\Controller\Msp\MspOutputController::class,
            \App\Controller\Msp\MspPostDataController::class,
            \App\Controller\Msp\MspRealtimeApiController::class,
            \App\Controller\N3pp\N3ppDataController::class,
            \App\Controller\N3pp\N3ppDescriptionController::class,
            \App\Controller\N3pp\N3ppOutputController::class,
            \App\Controller\N3pp\N3ppPostDataController::class,
            \App\Controller\N3pp\N3ppRealtimeApiController::class,
            \App\Controller\Pgl\PglStatsController::class,
            \App\Controller\Pgl\PglPostDataController::class,
            \App\Controller\Pgl\PglHeartbeatController::class,
            \App\Controller\Pgl\PglRealtimeApiController::class,
            \App\Controller\Energie\EnergieDataController::class,
            \App\Controller\Energie\EnergiePostDataController::class,
            \App\Controller\Energie\EnergieRealtimeApiController::class,
            \App\Controller\DisplayPresetController::class,
            \App\Controller\Gallery\GalleryUploadController::class,
            \App\Controller\Gallery\GalleryControlController::class,
            \App\Controller\Gallery\GalleryViewController::class,
            \App\Controller\Gallery\GalleryTrashController::class,
            \App\Controller\Gallery\GalleryNormalizeController::class,
            \App\Controller\McpController::class,
            \App\Controller\OAuth\OAuthController::class,
            \App\Controller\HealthReportController::class,
        ];
    }

    /**
     * @return list<class-string>
     */
    private static function servicesAndRepositories(): array
    {
        return [
            // Repositories
            \App\Repository\SensorReadRepository::class,
            \App\Repository\SensorRepository::class,
            \App\Repository\OutputRepository::class,
            \App\Repository\BoardRepository::class,
            \App\Repository\HeartbeatRepository::class,
            \App\Repository\MspSensorRepository::class,
            \App\Repository\MspOutputRepository::class,
            \App\Repository\N3ppSensorRepository::class,
            \App\Repository\N3ppOutputRepository::class,
            \App\Repository\PglRepository::class,
            \App\Repository\EnergieSensorRepository::class,
            \App\Repository\DisplayPresetRepository::class,
            \App\Repository\GalleryControlRepository::class,
            \App\Repository\UserRepository::class,
            // Services
            \App\Service\DisplayPresetService::class,
            \App\Display\PageCatalogRegistry::class,
            \App\Display\LayoutResolver::class,
            \App\Service\LogService::class,
            \App\Service\NotificationService::class,
            \App\Service\ErrorAlertService::class,
            \App\Service\OutputService::class,
            \App\Service\OutputCacheService::class,
            \App\Service\SensorDataService::class,
            \App\Service\SensorStatisticsService::class,
            \App\Service\StatisticsAggregatorService::class,
            \App\Service\ChartDataService::class,
            \App\Service\DateRangeExtractor::class,
            \App\Service\CsvExportService::class,
            \App\Service\TideCycleDetector::class,
            \App\Service\TideAnalysisService::class,
            \App\Service\WaterBalanceService::class,
            \App\Service\PumpService::class,
            \App\Service\SystemHealthService::class,
            \App\Service\UserService::class,
            \App\Service\ControlAuditLogger::class,
            \App\Service\GalleryTrashService::class,
            \App\Service\GalleryNormalizeService::class,
            \App\Service\TemplateRenderer::class,
            \App\Service\Realtime\Ffp3RealtimeDataProvider::class,
            \App\Service\Realtime\MspRealtimeDataProvider::class,
            \App\Service\Realtime\N3ppRealtimeDataProvider::class,
            \App\Service\Realtime\PglRealtimeDataProvider::class,
            \App\Service\Realtime\EnergieRealtimeDataProvider::class,
            // Sécurité
            \App\Security\AuthService::class,
            \App\Security\CsrfService::class,
            \App\Security\RoleAccessService::class,
            \App\Security\RateLimiter::class,
            // Middlewares
            \App\Middleware\ErrorHandlerMiddleware::class,
            \App\Middleware\CsrfMiddleware::class,
            \App\Middleware\AuthMiddleware::class,
            \App\Middleware\TokenAuthMiddleware::class,
            \App\Middleware\AuthGuardMiddleware::class,
            \App\Middleware\RateLimitMiddleware::class,
            // Commandes CRON
            \App\Command\RestartPumpCommand::class,
            \App\Command\CronOrchestrator::class,
            \App\Command\McpOAuthPurgeCommand::class,
            \App\Command\HealthReportCommand::class,
            // Connecteur MCP
            \App\Mcp\McpConfig::class,
            \App\Mcp\McpServer::class,
            \App\Mcp\ToolRegistry::class,
            \App\Repository\McpReadRepository::class,
            \App\Repository\McpOAuthRepository::class,
            \App\OAuth\AuthorizationServer::class,
            \App\OAuth\ClientResolver::class,
            // Exploitation du connecteur : mémoire, santé, CRON, fichiers
            \App\Repository\McpMemoryRepository::class,
            \App\Repository\McpHealthRepository::class,
            \App\Mcp\Memory\MemoryService::class,
            \App\Mcp\Health\LogLocator::class,
            \App\Mcp\Health\CronStatusReader::class,
            \App\Mcp\Health\HealthReportBuilder::class,
            \App\Mcp\Files\FileRoots::class,
            \App\Mcp\Files\SafeFileReader::class,
        ];
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function resolvableProvider(): iterable
    {
        foreach ([...self::controllers(), ...self::servicesAndRepositories()] as $class) {
            yield $class => [$class];
        }
    }

    /**
     * @param class-string $class
     *
     * @dataProvider resolvableProvider
     */
    public function testServiceIsResolvable(string $class): void
    {
        $container = self::buildContainer();

        $instance = $container->get($class);

        self::assertInstanceOf($class, $instance);
    }

    public function testMcpRegistryExposesAllToolsWithStableNames(): void
    {
        $registry = self::buildContainer()->get(\App\Mcp\ToolRegistry::class);
        $names = array_map(static fn (\App\Mcp\Tool $t): string => $t->name(), $registry->all());

        self::assertSame([
            'iot_overview', 'iot_server_health', 'iot_describe', 'iot_latest', 'iot_series', 'iot_stats',
            'iot_sensor_health', 'iot_tides', 'iot_energy', 'iot_pgl_stats', 'iot_outputs_state', 'iot_alerts', 'iot_contract_check',
            'iot_gallery_list', 'iot_gallery_latest', 'iot_plan_output_change', 'iot_apply_plan',
            'iot_set_output', 'iot_device_action', 'iot_gallery_delete', 'iot_sql', 'iot_sql_select', 'iot_db_schema', 'iot_migrate',
            'iot_accounts', 'iot_confirm_unlock',
            'iot_recall', 'iot_remember', 'iot_forget', 'iot_health_report', 'iot_health_history',
            'iot_cron_status', 'iot_list_files', 'iot_read_file', 'iot_grep_files',
        ], $names);
        foreach ($registry->all() as $tool) {
            self::assertSame('object', $tool->inputSchema()['type'], $tool->name());
            self::assertFalse($tool->inputSchema()['additionalProperties'], $tool->name());
            self::assertNotSame('', $tool->description());
            self::assertSame($tool->isReadOnly(), $tool->annotations()['readOnlyHint'], $tool->name());
        }
    }

    public function testPdoIsResolvable(): void
    {
        $container = self::buildContainer();

        self::assertInstanceOf(PDO::class, $container->get(PDO::class));
    }

    /**
     * Dépendances OPTIONNELLES qui doivent malgré tout être injectées.
     *
     * PHP-DI ignore volontairement les paramètres de constructeur ayant une valeur
     * par défaut (`ReflectionBasedAutowiring` : « Skip optional parameters »). Une
     * dépendance déclarée `?Type $x = null` reste donc à null tant qu'une entrée
     * explicite ne la passe pas — sans la moindre erreur au build.
     *
     * Pour les entrées ci-dessous, ce null n'est pas neutre : il débranche
     * silencieusement la lecture des réglages pilotés en BDD (table `serverSettings`
     * via OperationalSettingsService, seuils de la table outputs) et fait retomber le
     * code sur `.env`. Ce test échoue si l'un de ces câblages disparaît.
     *
     * Chemin pointé : propriété, ou chaîne de propriétés pour les dépendances
     * ré-empaquetées (`handler.hmacAuditLogger`).
     *
     * @return iterable<string, array{class-string, string}>
     */
    public static function wiredOptionalDependencyProvider(): iterable
    {
        $cases = [
            // CRON : seuils pilotés en BDD + alertes dérivées du POST.
            [\App\Command\CronOrchestrator::class, 'outputRepo'],
            [\App\Command\CronOrchestrator::class, 'offlineResolver'],
            [\App\Command\CronOrchestrator::class, 'ffp3DerivedAlerts'],
            [\App\Command\CronOrchestrator::class, 'n3ppDerivedAlerts'],
            [\App\Command\CronOrchestrator::class, 'mspDerivedAlerts'],
            [\App\Command\CronOrchestrator::class, 'galleryNormalizeService'],
            [\App\Service\GalleryNormalizeService::class, 'operationalSettings'],
            [\App\Command\CronOrchestrator::class, 'healthService.outputRepo'],
            [\App\Command\CronOrchestrator::class, 'healthService.operationalSettings'],
            [\App\Service\SystemHealthService::class, 'outputRepo'],
            [\App\Service\SystemHealthService::class, 'operationalSettings'],
            // Machine à états « disponibilité » : sans elle, les alertes hors-ligne
            // repartiraient à CHAQUE passage horaire du CRON (spam d'e-mails identiques).
            [\App\Service\SystemHealthService::class, 'availability'],
            [\App\Service\DeviceHealthService::class, 'availability'],
            [\App\Command\CronOrchestrator::class, 'healthService.availability'],
            [\App\Command\CronOrchestrator::class, 'deviceHealthService.availability'],
            // Réglages opérationnels (BDD > .env).
            [\App\Repository\SensorReadRepository::class, 'operationalSettings'],
            [\App\Repository\GallerySyncRepository::class, 'operationalSettings'],
            [\App\Service\SensorDataService::class, 'operationalSettings'],
            [\App\Service\HmacAuditLogger::class, 'operationalSettings'],
            [\App\Controller\Ffp3\HeartbeatController::class, 'operationalSettings'],
            [\App\Controller\Gallery\GalleryUploadController::class, 'operationalSettings'],
            // Manette FIRMWARE_FLAT_STATE_MODE (FirmwareStateCompat interne).
            [\App\Controller\Msp\MspOutputController::class, 'operationalSettings'],
            [\App\Controller\Msp\MspOutputController::class, 'displayPresets'],
            [\App\Controller\N3pp\N3ppOutputController::class, 'displayPresets'],
            [\App\Controller\N3pp\N3ppOutputController::class, 'operationalSettings'],
            [\App\Controller\Msp\MspRealtimeApiController::class, 'firmwareStateCompat.settings'],
            [\App\Controller\N3pp\N3ppRealtimeApiController::class, 'firmwareStateCompat.settings'],
            // Heartbeat n3pp/msp : audit HMAC + mode strict piloté en BDD.
            [\App\Controller\Msp\MspHeartbeatController::class, 'handler.hmacAuditLogger'],
            [\App\Controller\Msp\MspHeartbeatController::class, 'handler.hmacPolicyService'],
            [\App\Controller\Msp\MspHeartbeatController::class, 'handler.operationalSettings'],
            [\App\Controller\N3pp\N3ppHeartbeatController::class, 'handler.hmacAuditLogger'],
            [\App\Controller\N3pp\N3ppHeartbeatController::class, 'handler.hmacPolicyService'],
            [\App\Controller\N3pp\N3ppHeartbeatController::class, 'handler.operationalSettings'],
            [\App\Service\FirmwareStateCompat::class, 'settings'],
            // POST banc énergie (INA226) : audit HMAC + mode strict + réglages pilotés en BDD.
            [\App\Controller\Energie\EnergiePostDataController::class, 'hmacAuditLogger'],
            [\App\Controller\Energie\EnergiePostDataController::class, 'hmacPolicyService'],
            [\App\Controller\Energie\EnergiePostDataController::class, 'operationalSettings'],
            // Contrôle d'accès : sans ces dépendances, l'authentification BDD est inactive
            // et le filtrage par rôle laisse passer tous les chemins.
            [\App\Security\AuthService::class, 'userRepository'],
            [\App\Middleware\AuthGuardMiddleware::class, 'roleAccessService'],
            [\App\Middleware\AuthGuardMiddleware::class, 'templateRenderer'],
            [\App\Middleware\ErrorHandlerMiddleware::class, 'renderer'],
            [\App\Controller\Ffp3\CacheController::class, 'renderer'],
            // CRON : purge des jetons / codes / plans MCP expirés.
            [\App\Command\CronOrchestrator::class, 'mcpOAuthPurge'],
            [\App\Command\McpOAuthPurgeCommand::class, 'confirmRepo'],
            // CRON : rapport de santé régulier (sans lui, MCP_HEALTH_REPORT_ENABLED serait sans effet).
            [\App\Command\CronOrchestrator::class, 'healthReport'],
            // CRON : rétablissement pompe après arrêt MCP borné.
            [\App\Command\CronOrchestrator::class, 'mcpRestore'],
            // Rapport de santé : racine des galeries (UPLOADS_BASE_DIR, comme les pages galerie).
            [\App\Mcp\Health\HealthReportBuilder::class, 'projectRoot'],
        ];

        foreach ($cases as [$class, $path]) {
            yield $class . '::' . $path => [$class, $path];
        }
    }

    /**
     * @param class-string $class
     *
     * @dataProvider wiredOptionalDependencyProvider
     */
    public function testOptionalDependencyIsActuallyInjected(string $class, string $path): void
    {
        $instance = self::buildContainer()->get($class);

        $current = $instance;
        foreach (explode('.', $path) as $segment) {
            self::assertIsObject($current, "Chaîne interrompue avant « {$segment} » sur {$class}::{$path}");
            $property = self::findProperty($current, $segment);
            self::assertNotNull($property, "Propriété « {$segment} » introuvable sur " . $current::class);
            $property->setAccessible(true);
            $current = $property->isInitialized($current) ? $property->getValue($current) : null;
            self::assertNotNull(
                $current,
                "{$class}::{$path} est null : dépendance optionnelle non câblée dans config/dependencies.php "
                . '(PHP-DI n\'autowire pas les paramètres optionnels). Le code retomberait silencieusement sur .env.'
            );
        }
    }

    /**
     * Recherche une propriété en remontant la hiérarchie (une propriété privée
     * déclarée dans une classe parente n'est pas visible depuis l'instance enfant).
     */
    private static function findProperty(object $object, string $name): ?\ReflectionProperty
    {
        $reflection = new \ReflectionClass($object);
        while (!$reflection->hasProperty($name)) {
            $parent = $reflection->getParentClass();
            if ($parent === false) {
                return null;
            }
            $reflection = $parent;
        }

        return $reflection->getProperty($name);
    }
}

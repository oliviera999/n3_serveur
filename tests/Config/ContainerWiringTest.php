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
            \App\Controller\Gallery\GalleryUploadController::class,
            \App\Controller\Gallery\GalleryControlController::class,
            \App\Controller\Gallery\GalleryViewController::class,
            \App\Controller\Gallery\GalleryTrashController::class,
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
            \App\Repository\GalleryControlRepository::class,
            \App\Repository\UserRepository::class,
            // Services
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
            \App\Service\TemplateRenderer::class,
            \App\Service\Realtime\Ffp3RealtimeDataProvider::class,
            \App\Service\Realtime\MspRealtimeDataProvider::class,
            \App\Service\Realtime\N3ppRealtimeDataProvider::class,
            \App\Service\Realtime\PglRealtimeDataProvider::class,
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

    public function testPdoIsResolvable(): void
    {
        $container = self::buildContainer();

        self::assertInstanceOf(PDO::class, $container->get(PDO::class));
    }
}

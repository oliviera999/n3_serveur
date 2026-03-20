<?php

declare(strict_types=1);

use App\Config\Database;
use App\Repository\BoardRepository;
use App\Repository\MspOutputRepository;
use App\Repository\MspSensorRepository;
use App\Repository\N3ppOutputRepository;
use App\Repository\N3ppSensorRepository;
use App\Repository\OutputRepository;
use App\Repository\SensorReadRepository;
use App\Repository\SensorRepository;
use App\Service\ChartDataService;
use App\Service\ErrorAlertService;
use App\Service\LogService;
use App\Service\NotificationService;
use App\Service\OutputCacheService;
use App\Service\OutputService;
use App\Service\PumpService;
use App\Service\Realtime\Ffp3RealtimeDataProvider;
use App\Service\Realtime\MspRealtimeDataProvider;
use App\Service\Realtime\N3ppRealtimeDataProvider;
use App\Service\RealtimeDataService;
use App\Service\SensorDataService;
use App\Service\SensorStatisticsService;
use App\Service\StatisticsAggregatorService;
use App\Service\SystemHealthService;
use App\Service\TemplateRenderer;
use App\Service\TideCycleDetector;
use App\Service\TideAnalysisService;
use App\Service\WaterBalanceService;
use App\Security\CsrfService;
use App\Security\AuthService;
use Psr\Container\ContainerInterface;

return [
    // ====================================================================
    // DATABASE CONNECTION (Singleton)
    // ====================================================================
    PDO::class => function (ContainerInterface $c) {
        return Database::getConnection();
    },

    // ====================================================================
    // REPOSITORIES
    // ====================================================================
    SensorReadRepository::class => function (ContainerInterface $c) {
        return new SensorReadRepository($c->get(PDO::class));
    },

    SensorRepository::class => function (ContainerInterface $c) {
        return new SensorRepository($c->get(PDO::class));
    },

    OutputRepository::class => function (ContainerInterface $c) {
        return new OutputRepository($c->get(PDO::class));
    },

    BoardRepository::class => function (ContainerInterface $c) {
        return new BoardRepository($c->get(PDO::class));
    },

    MspSensorRepository::class => function (ContainerInterface $c) {
        return new MspSensorRepository($c->get(PDO::class));
    },

    N3ppSensorRepository::class => function (ContainerInterface $c) {
        return new N3ppSensorRepository($c->get(PDO::class));
    },

    MspOutputRepository::class => function (ContainerInterface $c) {
        return new MspOutputRepository($c->get(PDO::class), $c->get(BoardRepository::class));
    },

    N3ppOutputRepository::class => function (ContainerInterface $c) {
        return new N3ppOutputRepository($c->get(PDO::class), $c->get(BoardRepository::class));
    },

    // ====================================================================
    // SERVICES
    // ====================================================================
    LogService::class => function (ContainerInterface $c) {
        return new LogService();
    },

    SensorStatisticsService::class => function (ContainerInterface $c) {
        return new SensorStatisticsService($c->get(PDO::class));
    },

    StatisticsAggregatorService::class => function (ContainerInterface $c) {
        return new StatisticsAggregatorService($c->get(SensorStatisticsService::class));
    },

    ChartDataService::class => function (ContainerInterface $c) {
        return new ChartDataService();
    },

    \App\Service\DateRangeExtractor::class => function (ContainerInterface $c) {
        return new \App\Service\DateRangeExtractor(
            $c->get(\App\Security\CsrfService::class)
        );
    },

    \App\Service\CsvExportService::class => function (ContainerInterface $c) {
        return new \App\Service\CsvExportService();
    },

    TideCycleDetector::class => function (ContainerInterface $c) {
        return new TideCycleDetector();
    },

    TideAnalysisService::class => function (ContainerInterface $c) {
        return new TideAnalysisService(
            $c->get(SensorReadRepository::class),
            $c->get(TideCycleDetector::class)
        );
    },

    WaterBalanceService::class => function (ContainerInterface $c) {
        return new WaterBalanceService(
            $c->get(SensorReadRepository::class),
            $c->get(TideCycleDetector::class)
        );
    },

    PumpService::class => function (ContainerInterface $c) {
        return new PumpService($c->get(PDO::class));
    },

    OutputService::class => function (ContainerInterface $c) {
        return new OutputService(
            $c->get(OutputRepository::class),
            $c->get(BoardRepository::class),
            $c->get(OutputCacheService::class),
            $c->get(SensorReadRepository::class)
        );
    },

    SensorDataService::class => function (ContainerInterface $c) {
        return new SensorDataService(
            $c->get(PDO::class),
            $c->get(LogService::class)
        );
    },

    NotificationService::class => function (ContainerInterface $c) {
        return new NotificationService($c->get(LogService::class));
    },

    ErrorAlertService::class => function (ContainerInterface $c) {
        return new ErrorAlertService(
            $c->get(LogService::class),
            $c->get(NotificationService::class)
        );
    },

    OutputCacheService::class => function (ContainerInterface $c) {
        return new OutputCacheService();
    },

    SystemHealthService::class => function (ContainerInterface $c) {
        return new SystemHealthService(
            $c->get(SensorReadRepository::class),
            $c->get(NotificationService::class),
            $c->get(LogService::class)
        );
    },

    CsrfService::class => function (ContainerInterface $c) {
        return new CsrfService();
    },

    AuthService::class => function (ContainerInterface $c) {
        return new AuthService();
    },

    TemplateRenderer::class => function (ContainerInterface $c) {
        $templatesPath = __DIR__ . '/../templates';
        $resolved = realpath($templatesPath);
        return new TemplateRenderer(
            $resolved !== false ? $resolved : $templatesPath,
            ($_ENV['ENV'] ?? 'prod') === 'prod',
            $c->get(CsrfService::class),
            $c->get(AuthService::class)
        );
    },

    Ffp3RealtimeDataProvider::class => function (ContainerInterface $c) {
        return new Ffp3RealtimeDataProvider(
            $c->get(SensorReadRepository::class),
            $c->get(OutputRepository::class)
        );
    },

    // Alias pour compatibilité avec l'ancien nom
    RealtimeDataService::class => function (ContainerInterface $c) {
        return $c->get(Ffp3RealtimeDataProvider::class);
    },

    MspRealtimeDataProvider::class => function (ContainerInterface $c) {
        return new MspRealtimeDataProvider(
            $c->get(MspSensorRepository::class),
            $c->get(MspOutputRepository::class)
        );
    },

    N3ppRealtimeDataProvider::class => function (ContainerInterface $c) {
        return new N3ppRealtimeDataProvider(
            $c->get(N3ppSensorRepository::class),
            $c->get(N3ppOutputRepository::class)
        );
    },

    // ====================================================================
    // CONTROLLERS (Définis explicitement pour éviter les problèmes d'autowiring)
    // ====================================================================
    \App\Controller\Ffp3\OutputController::class => function (ContainerInterface $c) {
        return new \App\Controller\Ffp3\OutputController(
            $c->get(\App\Service\OutputService::class),
            $c->get(\App\Service\TemplateRenderer::class),
            $c->get(\App\Repository\SensorReadRepository::class),
            $c->get(\App\Service\OutputCacheService::class),
            $c->get(\App\Service\LogService::class)
        );
    },

    \App\Controller\Ffp3\PostDataController::class => function (ContainerInterface $c) {
        return new \App\Controller\Ffp3\PostDataController(
            $c->get(\App\Service\LogService::class),
            $c->get(\App\Service\ErrorAlertService::class),
            $c->get(\App\Repository\SensorRepository::class),
            $c->get(\App\Repository\OutputRepository::class),
            $c->get(\App\Repository\BoardRepository::class)
        );
    },

    \App\Controller\Ffp3\HeartbeatController::class => function (ContainerInterface $c) {
        return new \App\Controller\Ffp3\HeartbeatController(
            $c->get(\App\Service\LogService::class),
            $c->get(\App\Service\ErrorAlertService::class),
            $c->get(PDO::class)
        );
    },

    \App\Controller\Ffp3\AquaponieController::class => function (ContainerInterface $c) {
        return new \App\Controller\Ffp3\AquaponieController(
            $c->get(\App\Repository\SensorReadRepository::class),
            $c->get(\App\Service\StatisticsAggregatorService::class),
            $c->get(\App\Service\ChartDataService::class),
            $c->get(\App\Service\WaterBalanceService::class),
            $c->get(\App\Service\TemplateRenderer::class),
            $c->get(\App\Service\LogService::class),
            $c->get(\App\Service\DateRangeExtractor::class),
            $c->get(\App\Service\CsvExportService::class)
        );
    },

    \App\Controller\Ffp3\AquaponieDescriptionController::class => function (ContainerInterface $c) {
        return new \App\Controller\Ffp3\AquaponieDescriptionController(
            $c->get(\App\Service\TemplateRenderer::class)
        );
    },

    \App\Controller\LocalDataPagesController::class => function (ContainerInterface $c) {
        return new \App\Controller\LocalDataPagesController(
            $c->get(\App\Service\TemplateRenderer::class)
        );
    },

    \App\Controller\Ffp3\DashboardController::class => function (ContainerInterface $c) {
        return new \App\Controller\Ffp3\DashboardController(
            $c->get(\App\Repository\SensorReadRepository::class),
            $c->get(\App\Service\SensorStatisticsService::class),
            $c->get(\App\Service\TemplateRenderer::class),
            $c->get(\App\Service\DateRangeExtractor::class),
            $c->get(\App\Service\CsvExportService::class)
        );
    },

    \App\Controller\Ffp3\ExportController::class => function (ContainerInterface $c) {
        return new \App\Controller\Ffp3\ExportController(
            $c->get(\App\Repository\SensorReadRepository::class),
            $c->get(\App\Service\CsvExportService::class),
            $c->get(\App\Service\DateRangeExtractor::class)
        );
    },

    \App\Controller\Ffp3\TideStatsController::class => function (ContainerInterface $c) {
        return new \App\Controller\Ffp3\TideStatsController(
            $c->get(\App\Service\TideAnalysisService::class),
            $c->get(\App\Service\TemplateRenderer::class),
            $c->get(\App\Service\DateRangeExtractor::class)
        );
    },

    \App\Controller\Ffp3\RealtimeApiController::class => function (ContainerInterface $c) {
        return new \App\Controller\Ffp3\RealtimeApiController(
            $c->get(Ffp3RealtimeDataProvider::class)
        );
    },

    \App\Controller\HomeController::class => function (ContainerInterface $c) {
        return new \App\Controller\HomeController(
            $c->get(\App\Service\TemplateRenderer::class)
        );
    },

    \App\Controller\Ffp3\CacheController::class => function (ContainerInterface $c) {
        return new \App\Controller\Ffp3\CacheController(
            $c->get(\App\Service\OutputCacheService::class)
        );
    },

    \App\Controller\SupervisionController::class => function (ContainerInterface $c) {
        return new \App\Controller\SupervisionController(
            $c->get(\App\Service\TemplateRenderer::class)
        );
    },

    \App\Middleware\ErrorHandlerMiddleware::class => function (ContainerInterface $c) {
        return new \App\Middleware\ErrorHandlerMiddleware(
            $c->get(\App\Service\LogService::class),
            $c->get(\App\Service\ErrorAlertService::class)
        );
    },

    \App\Middleware\AuthMiddleware::class => function (ContainerInterface $c) {
        return new \App\Middleware\AuthMiddleware(
            $c->get(\App\Security\AuthService::class)
        );
    },

    \App\Middleware\TokenAuthMiddleware::class => function (ContainerInterface $c) {
        return new \App\Middleware\TokenAuthMiddleware(
            $c->get(\App\Security\AuthService::class)
        );
    },

    \App\Middleware\AuthGuardMiddleware::class => function (ContainerInterface $c) {
        return new \App\Middleware\AuthGuardMiddleware(
            $c->get(\App\Security\AuthService::class),
            $c->get(\App\Middleware\AuthMiddleware::class),
            $c->get(\App\Middleware\TokenAuthMiddleware::class)
        );
    },

    \App\Controller\AuthController::class => function (ContainerInterface $c) {
        return new \App\Controller\AuthController(
            $c->get(\App\Security\AuthService::class),
            $c->get(\App\Service\TemplateRenderer::class),
            $c->get(\App\Security\CsrfService::class)
        );
    },

    // ====================================================================
    // CONTROLLERS MSP1 / N3PP / GALLERY
    // ====================================================================
    \App\Controller\Msp\MspPostDataController::class => function (ContainerInterface $c) {
        return new \App\Controller\Msp\MspPostDataController(
            $c->get(\App\Service\LogService::class),
            $c->get(MspSensorRepository::class)
        );
    },

    \App\Controller\Msp\MspOutputController::class => function (ContainerInterface $c) {
        return new \App\Controller\Msp\MspOutputController(
            $c->get(\App\Service\LogService::class),
            $c->get(TemplateRenderer::class),
            $c->get(AuthService::class),
            $c->get(MspOutputRepository::class),
            $c->get(MspSensorRepository::class)
        );
    },

    \App\Controller\Msp\MspDataController::class => function (ContainerInterface $c) {
        return new \App\Controller\Msp\MspDataController(
            $c->get(TemplateRenderer::class),
            $c->get(MspSensorRepository::class),
            $c->get(CsrfService::class),
            $c->get(\App\Service\DateRangeExtractor::class),
            $c->get(\App\Service\CsvExportService::class),
            $c->get(ChartDataService::class)
        );
    },

    \App\Controller\Msp\MspDescriptionController::class => function (ContainerInterface $c) {
        return new \App\Controller\Msp\MspDescriptionController(
            $c->get(TemplateRenderer::class)
        );
    },

    \App\Controller\N3pp\N3ppPostDataController::class => function (ContainerInterface $c) {
        return new \App\Controller\N3pp\N3ppPostDataController(
            $c->get(\App\Service\LogService::class),
            $c->get(N3ppSensorRepository::class)
        );
    },

    \App\Controller\N3pp\N3ppOutputController::class => function (ContainerInterface $c) {
        return new \App\Controller\N3pp\N3ppOutputController(
            $c->get(\App\Service\LogService::class),
            $c->get(TemplateRenderer::class),
            $c->get(AuthService::class),
            $c->get(N3ppOutputRepository::class),
            $c->get(N3ppSensorRepository::class)
        );
    },

    \App\Controller\N3pp\N3ppDataController::class => function (ContainerInterface $c) {
        return new \App\Controller\N3pp\N3ppDataController(
            $c->get(TemplateRenderer::class),
            $c->get(N3ppSensorRepository::class),
            $c->get(CsrfService::class),
            $c->get(\App\Service\DateRangeExtractor::class),
            $c->get(\App\Service\CsvExportService::class),
            $c->get(ChartDataService::class)
        );
    },

    \App\Controller\N3pp\N3ppDescriptionController::class => function (ContainerInterface $c) {
        return new \App\Controller\N3pp\N3ppDescriptionController(
            $c->get(TemplateRenderer::class)
        );
    },

    \App\Controller\N3pp\N3ppRealtimeApiController::class => function (ContainerInterface $c) {
        return new \App\Controller\N3pp\N3ppRealtimeApiController(
            $c->get(N3ppRealtimeDataProvider::class)
        );
    },

    \App\Controller\Msp\MspRealtimeApiController::class => function (ContainerInterface $c) {
        return new \App\Controller\Msp\MspRealtimeApiController(
            $c->get(MspRealtimeDataProvider::class)
        );
    },

    \App\Controller\Gallery\GalleryUploadController::class => function (ContainerInterface $c) {
        return new \App\Controller\Gallery\GalleryUploadController(
            $c->get(\App\Service\LogService::class)
        );
    },

    \App\Controller\Gallery\GalleryViewController::class => function (ContainerInterface $c) {
        return new \App\Controller\Gallery\GalleryViewController(
            $c->get(TemplateRenderer::class)
        );
    },
];


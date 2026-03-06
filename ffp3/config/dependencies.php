<?php

declare(strict_types=1);

use App\Config\Database;
use App\Repository\BoardRepository;
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
use App\Service\RealtimeDataService;
use App\Service\SensorDataService;
use App\Service\SensorStatisticsService;
use App\Service\StatisticsAggregatorService;
use App\Service\SystemHealthService;
use App\Service\TemplateRenderer;
use App\Service\TideCycleDetector;
use App\Service\TideAnalysisService;
use App\Service\WaterBalanceService;
use App\Service\OutputSyncService;
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

    OutputSyncService::class => function (ContainerInterface $c) {
        return new OutputSyncService(
            $c->get(OutputRepository::class),
            $c->get(LogService::class)
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
        return new TemplateRenderer(
            __DIR__ . '/../templates',
            ($_ENV['ENV'] ?? 'prod') === 'prod',
            $c->get(CsrfService::class)
        );
    },

    RealtimeDataService::class => function (ContainerInterface $c) {
        return new RealtimeDataService(
            $c->get(SensorReadRepository::class),
            $c->get(OutputRepository::class),
            $c->get(PDO::class)
        );
    },

    // ====================================================================
    // CONTROLLERS (Définis explicitement pour éviter les problèmes d'autowiring)
    // ====================================================================
    \App\Controller\OutputController::class => function (ContainerInterface $c) {
        return new \App\Controller\OutputController(
            $c->get(\App\Service\OutputService::class),
            $c->get(\App\Service\TemplateRenderer::class),
            $c->get(\App\Repository\SensorReadRepository::class),
            $c->get(\App\Service\OutputCacheService::class)
        );
    },

    \App\Controller\PostDataController::class => function (ContainerInterface $c) {
        return new \App\Controller\PostDataController(
            $c->get(\App\Service\LogService::class),
            $c->get(\App\Service\ErrorAlertService::class),
            $c->get(\App\Service\OutputCacheService::class),
            $c->get(\App\Repository\SensorRepository::class),
            $c->get(\App\Repository\OutputRepository::class),
            $c->get(\App\Repository\BoardRepository::class)
        );
    },

    \App\Controller\HeartbeatController::class => function (ContainerInterface $c) {
        return new \App\Controller\HeartbeatController(
            $c->get(\App\Service\LogService::class),
            $c->get(\App\Service\ErrorAlertService::class)
        );
    },

    \App\Controller\AquaponieController::class => function (ContainerInterface $c) {
        return new \App\Controller\AquaponieController(
            $c->get(\App\Repository\SensorReadRepository::class),
            $c->get(\App\Service\StatisticsAggregatorService::class),
            $c->get(\App\Service\ChartDataService::class),
            $c->get(\App\Service\WaterBalanceService::class),
            $c->get(\App\Service\TemplateRenderer::class),
            $c->get(\App\Service\LogService::class),
            $c->get(\App\Security\CsrfService::class)
        );
    },

    \App\Controller\DashboardController::class => function (ContainerInterface $c) {
        return new \App\Controller\DashboardController(
            $c->get(\App\Repository\SensorReadRepository::class),
            $c->get(\App\Service\SensorStatisticsService::class),
            $c->get(\App\Service\TemplateRenderer::class)
        );
    },

    \App\Controller\ExportController::class => function (ContainerInterface $c) {
        return new \App\Controller\ExportController(
            $c->get(\App\Repository\SensorReadRepository::class)
        );
    },

    \App\Controller\TideStatsController::class => function (ContainerInterface $c) {
        return new \App\Controller\TideStatsController(
            $c->get(\App\Service\TideAnalysisService::class),
            $c->get(\App\Service\TemplateRenderer::class),
            $c->get(\App\Security\CsrfService::class)
        );
    },

    \App\Controller\RealtimeApiController::class => function (ContainerInterface $c) {
        return new \App\Controller\RealtimeApiController(
            $c->get(\App\Service\RealtimeDataService::class)
        );
    },

    \App\Controller\HomeController::class => function (ContainerInterface $c) {
        return new \App\Controller\HomeController(
            $c->get(\App\Service\TemplateRenderer::class)
        );
    },

    \App\Controller\CacheController::class => function (ContainerInterface $c) {
        return new \App\Controller\CacheController();
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

    \App\Controller\AuthController::class => function (ContainerInterface $c) {
        return new \App\Controller\AuthController(
            $c->get(\App\Security\AuthService::class),
            $c->get(\App\Service\TemplateRenderer::class),
            $c->get(\App\Security\CsrfService::class)
        );
    },
];


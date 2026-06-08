<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use App\Command\CronOrchestrator;

$ts = date('Y-m-d H:i:s');
error_log(sprintf('[%s] [run-cron] SAPI: %s', $ts, php_sapi_name()));
if (php_sapi_name() !== 'cli' && strpos(php_sapi_name(), 'cgi') === false) {
    die('This script can only be run from the command line.');
}

try {
    // Câblage via le conteneur DI (mêmes définitions que l'application web).
    /** @var \Psr\Container\ContainerInterface $container */
    $container = require __DIR__ . '/config/container.php';
    $orchestrator = $container->get(CronOrchestrator::class);
    $orchestrator->execute();
    exit(0);
} catch (\Throwable $e) {
    $ts = date('Y-m-d H:i:s');
    error_log(sprintf(
        '[%s] [run-cron] Erreur: %s in %s:%d',
        $ts,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));
    error_log(sprintf('[%s] [run-cron] Trace: %s', $ts, $e->getTraceAsString()));
    exit(1);
}

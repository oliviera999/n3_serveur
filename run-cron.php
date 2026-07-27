<?php

declare(strict_types=1);

// Garde AVANT l'autoload : refuse toute execution depuis une requete web.
// L'ancienne garde acceptait n'importe quel SAPI contenant « cgi » — donc
// `cgi-fcgi`, celui de PHP-FPM : un `GET /run-cron.php` declenchait le CRON
// sans authentification (audit 2026-07, S11). `CliGuard` conserve le support
// des crontabs `php-cgi` en exigeant l'absence de contexte HTTP.
require_once __DIR__ . '/src/Util/CliGuard.php';
\App\Util\CliGuard::assertCli('run-cron.php');

require_once __DIR__ . '/vendor/autoload.php';

use App\Command\CronOrchestrator;

$ts = date('Y-m-d H:i:s');
error_log(sprintf('[%s] [run-cron] SAPI: %s', $ts, php_sapi_name()));

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

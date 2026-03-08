<?php

// run-cron.php

require_once __DIR__ . '/vendor/autoload.php';

use App\Command\ProcessTasksCommand;

// Pour s'assurer que le script est bien exécuté depuis la ligne de commande
$ts = date('Y-m-d H:i:s');
error_log(sprintf('[%s] [run-cron] SAPI: %s', $ts, php_sapi_name()));
if (php_sapi_name() !== 'cli' && strpos(php_sapi_name(), 'cgi') === false) {
    die('This script can only be run from the command line.');
}

try {
    $command = new ProcessTasksCommand();
    $command->execute();
    exit(0); // Succès
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
    exit(1); // Échec
} 
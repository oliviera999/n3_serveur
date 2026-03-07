<?php

// run-cron.php

require_once __DIR__ . '/vendor/autoload.php';

use App\Command\ProcessTasksCommand;

// Pour s'assurer que le script est bien exécuté depuis la ligne de commande
error_log("SAPI name is: " . php_sapi_name());
if (php_sapi_name() !== 'cli' && strpos(php_sapi_name(), 'cgi') === false) {
    die('This script can only be run from the command line.');
}

try {
    $command = new ProcessTasksCommand();
    $command->execute();
    exit(0); // Succès
} catch (\Throwable $e) {
    // En cas d'erreur, on log le message.
    // On pourrait utiliser le LogService ici, mais par simplicité on utilise error_log.
    error_log("Erreur lors de l'exécution des tâches CRON : " . $e->getMessage());
    error_log($e->getTraceAsString());
    exit(1); // Échec
} 
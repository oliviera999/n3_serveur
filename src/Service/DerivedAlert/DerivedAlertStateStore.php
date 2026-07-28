<?php

declare(strict_types=1);

namespace App\Service\DerivedAlert;

use App\Util\JsonFileStore;

/**
 * Persistance (fichier JSON) de l'état des alertes dérivées entre deux runs
 * CRON : latches anti-spam (batterie, sol sec), machine trop-plein, derniers
 * compteurs vus (bootCount), dernier état chauffage…
 *
 * Un fichier par famille, sous `var/cache/` (même emplacement que l'état horaire
 * du CronOrchestrator). La mécanique de lecture/écriture tolérante aux erreurs est
 * portée par {@see JsonFileStore} : un fichier absent/corrompu retombe sur l'état
 * vide — au pire une alerte est ré-évaluée, l'AlertThrottler dédoublonne.
 */
class DerivedAlertStateStore extends JsonFileStore
{
}

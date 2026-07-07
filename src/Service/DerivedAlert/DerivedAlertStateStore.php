<?php

declare(strict_types=1);

namespace App\Service\DerivedAlert;

/**
 * Persistance légère (fichier JSON) de l'état des alertes dérivées entre deux runs
 * CRON : latches anti-spam (batterie, sol sec), machine trop-plein, derniers
 * compteurs vus (bootCount), dernier état chauffage…
 *
 * Un fichier par famille, sous `var/cache/` (même emplacement que l'état horaire
 * du CronOrchestrator). Tolérant aux erreurs : un fichier absent/corrompu retombe
 * sur l'état vide — au pire une alerte est ré-évaluée, l'AlertThrottler dédoublonne.
 */
class DerivedAlertStateStore
{
    public function __construct(private string $filePath)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function load(): array
    {
        if (!is_file($this->filePath)) {
            return [];
        }

        $raw = @file_get_contents($this->filePath);
        if ($raw === false || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $state
     */
    public function save(array $state): void
    {
        $dir = dirname($this->filePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        @file_put_contents($this->filePath, json_encode($state, JSON_PRETTY_PRINT));
    }
}

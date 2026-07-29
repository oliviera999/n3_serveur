<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Persistance légère d'un état applicatif sous forme de fichier JSON.
 *
 * Extrait de {@see \App\Service\DerivedAlert\DerivedAlertStateStore} (qui en hérite
 * désormais) pour être réutilisable par toute logique ayant besoin de se souvenir
 * d'un état entre deux runs CRON — machines à états d'alerte, latches anti-spam,
 * derniers compteurs vus…
 *
 * Tolérant aux erreurs : un fichier absent, illisible ou corrompu retombe sur l'état
 * vide plutôt que de faire échouer l'appelant. Les écritures concurrentes ne sont pas
 * un sujet : les seuls écrivains sont les commandes CRON, sérialisées par le verrou
 * `flock` du {@see \App\Command\CronOrchestrator}.
 */
class JsonFileStore
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

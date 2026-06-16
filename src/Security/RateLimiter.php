<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Limiteur de débit simple à fenêtre glissante, persistant sur disque.
 *
 * Conçu pour borner le nombre de requêtes sensibles (ex. tentatives de login)
 * par clé (typiquement une IP) sur une fenêtre de temps donnée. Le stockage
 * fichier évite toute dépendance externe (APCu/Redis) et survit entre requêtes.
 *
 * Tolérant aux pannes (fail-open) : en cas d'impossibilité d'écriture, le
 * limiteur n'enferme jamais l'utilisateur — la sécurité applicative reste
 * assurée par les autres couches.
 */
class RateLimiter
{
    private string $storageDir;

    public function __construct(?string $storageDir = null)
    {
        $this->storageDir = $storageDir !== null && $storageDir !== ''
            ? rtrim($storageDir, DIRECTORY_SEPARATOR)
            : sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'n3_ratelimit';
    }

    /**
     * Enregistre une occurrence pour la clé et retourne le nombre d'occurrences
     * survenues dans la fenêtre (l'occurrence courante incluse).
     */
    public function hit(string $key, int $windowSeconds): int
    {
        $now = time();
        $file = $this->resolveFile($key);
        if ($file === null) {
            return 1; // fail-open : pas de stockage disponible
        }

        $fp = @fopen($file, 'c+');
        if ($fp === false) {
            return 1; // fail-open
        }

        try {
            flock($fp, LOCK_EX);
            $timestamps = $this->readTimestamps($fp, $now, $windowSeconds);
            $timestamps[] = $now;

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, (string) json_encode(array_values($timestamps)));
            fflush($fp);

            return count($timestamps);
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * Retourne le nombre d'occurrences dans la fenêtre sans en enregistrer une nouvelle.
     */
    public function attempts(string $key, int $windowSeconds): int
    {
        $file = $this->resolveFile($key, false);
        if ($file === null || !is_file($file)) {
            return 0;
        }

        $fp = @fopen($file, 'r');
        if ($fp === false) {
            return 0;
        }

        try {
            flock($fp, LOCK_SH);
            return count($this->readTimestamps($fp, time(), $windowSeconds));
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * Réinitialise le compteur pour une clé (ex. après une connexion réussie).
     */
    public function clear(string $key): void
    {
        $file = $this->resolveFile($key, false);
        if ($file !== null && is_file($file)) {
            @unlink($file);
        }
    }

    /**
     * @param resource $fp
     * @return list<int>
     */
    private function readTimestamps($fp, int $now, int $windowSeconds): array
    {
        $raw = stream_get_contents($fp);
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $valid = [];
        foreach ($decoded as $ts) {
            if (is_int($ts) && ($now - $ts) < $windowSeconds) {
                $valid[] = $ts;
            }
        }

        return $valid;
    }

    /**
     * Construit le chemin du fichier de compteur, en créant le dossier si besoin.
     */
    private function resolveFile(string $key, bool $createDir = true): ?string
    {
        if ($createDir && !is_dir($this->storageDir)) {
            @mkdir($this->storageDir, 0700, true);
        }

        if (!is_dir($this->storageDir)) {
            return null;
        }

        return $this->storageDir . DIRECTORY_SEPARATOR . sha1($key) . '.json';
    }
}

<?php

declare(strict_types=1);

namespace App\Config;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $instance = null;

    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            // Charge les variables d'environnement une seule fois (si .env existe)
            Env::load();

            // Vérification de la présence des variables indispensables
            foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $var) {
                if (!isset($_ENV[$var]) || $_ENV[$var] === '') {
                    throw new \RuntimeException("La variable d'environnement '{$var}' est manquante ou vide. Veuillez l'ajouter dans votre .env ou l'environnement serveur.");
                }
            }

            $host = $_ENV['DB_HOST'];
            $db = $_ENV['DB_NAME'];
            $user = $_ENV['DB_USER'];
            $pass = $_ENV['DB_PASS'];

            $dsn = "mysql:host={$host};dbname={$db};charset=utf8mb4";
            try {
                $options = [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ];
                if (defined('PDO::MYSQL_ATTR_CONNECT_TIMEOUT')) {
                    $options[PDO::MYSQL_ATTR_CONNECT_TIMEOUT] = 5;
                }
                // Verrouille le fuseau de session MySQL sur APP_TIMEZONE (heure serveur).
                // Sans cela, `time_zone` vaut souvent `SYSTEM` (dépend de l'OS du serveur DB) :
                // `reading_time`/`last_request` (CURRENT_TIMESTAMP / NOW()) seraient alors écrits
                // dans un fuseau non maîtrisé. On force l'offset UTC courant d'APP_TIMEZONE
                // (ex. '+02:00') : robuste, sans dépendre des tables de fuseaux MySQL, et
                // recalculé à chaque connexion donc correct été comme hiver (DST).
                if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
                    $options[PDO::MYSQL_ATTR_INIT_COMMAND] =
                        "SET time_zone = '" . self::currentUtcOffset() . "'";
                }
                self::$instance = new PDO($dsn, $user, $pass, $options);
            } catch (PDOException $e) {
                throw new \RuntimeException('DB connection failed: ' . $e->getMessage());
            }
        }

        return self::$instance;
    }

    /**
     * Offset UTC courant d'APP_TIMEZONE au format MySQL ('+HH:MM' / '-HH:MM').
     * Défaut prudent '+00:00' (UTC) si le fuseau est invalide.
     */
    private static function currentUtcOffset(): string
    {
        $tzName = $_ENV['APP_TIMEZONE'] ?? 'Europe/Paris';
        try {
            $offset = (new \DateTimeZone($tzName))->getOffset(new \DateTimeImmutable('now'));
        } catch (\Exception) {
            return '+00:00';
        }

        $sign = $offset < 0 ? '-' : '+';
        $abs = abs($offset);

        return sprintf('%s%02d:%02d', $sign, intdiv($abs, 3600), intdiv($abs % 3600, 60));
    }
}

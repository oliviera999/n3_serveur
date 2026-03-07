<?php

namespace App\Config;

use PDO;
use PDOException;
use App\Config\Env;

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
            $db   = $_ENV['DB_NAME'];
            $user = $_ENV['DB_USER'];
            $pass = $_ENV['DB_PASS'];

            $dsn = "mysql:host={$host};dbname={$db};charset=utf8mb4";
            try {
                self::$instance = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
            } catch (PDOException $e) {
                throw new \RuntimeException('DB connection failed: ' . $e->getMessage());
            }
        }

        return self::$instance;
    }
} 
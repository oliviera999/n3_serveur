<?php

namespace App\Config;

use PDO;
use PDOException;
use Dotenv\Dotenv;

class Database
{
    private static ?PDO $instance = null;

    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            // Charger les variables d'environnement si possible
            $root = dirname(__DIR__, 2);
            if (file_exists($root . '/.env')) {
                $dotenv = Dotenv::createImmutable($root);
                $dotenv->load();
            } elseif (file_exists($root . '/env.dist')) {
                // Si .env n'existe pas, on tente de charger env.dist pour le développement
                $dotenv = Dotenv::createImmutable($root, 'env.dist');
                $dotenv->load();
            }

            $host = $_ENV['DB_HOST'] ?? 'localhost';
            $db   = $_ENV['DB_NAME'] ?? '';
            $user = $_ENV['DB_USER'] ?? '';
            $pass = $_ENV['DB_PASS'] ?? '';

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
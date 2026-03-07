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
            // Charger les variables d'environnement
            $root = dirname(__DIR__, 2);
            if (!file_exists($root . '/.env')) {
                throw new \RuntimeException("Le fichier .env est introuvable. Assurez-vous qu'il existe à la racine du projet.");
            }
            // Utiliser un dépôt mutable pour que les variables du fichier .env
            // puissent écraser d'éventuelles variables déjà définies par l'environnement
            $dotenv = Dotenv::createMutable($root);
            $dotenv->load();

            // Valider que les variables nécessaires sont présentes
            try {
                $dotenv->required(['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS']);
            } catch (\Dotenv\Exception\ValidationException $e) {
                throw new \RuntimeException("Erreur de validation des variables d'environnement : " . $e->getMessage());
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
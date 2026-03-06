<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Classe de configuration des noms de tables selon l'environnement
 *
 * Permet de basculer entre les tables de production (ffp3Data, ffp3Outputs)
 * et les tables de test (ffp3Data2, ffp3Outputs2, ffp3Data3, ffp3Outputs3) via la variable ENV
 */
class TableConfig
{
    private const ENVIRONMENTS = ['prod', 'test', 'test3', 's3'];

    /**
     * Détermine si on est en environnement de test (test ou test3)
     * s3 est du prod, donc isTest() retourne false pour s3
     */
    public static function isTest(): bool
    {
        if (!isset($_ENV['ENV'])) {
            Env::load();
        }
        $env = $_ENV['ENV'] ?? 'prod';
        return in_array($env, ['test', 'test3'], true);
    }

    /**
     * Retourne l'environnement actuel (prod, test, test3 ou s3)
     */
    public static function getEnvironment(): string
    {
        if (!isset($_ENV['ENV'])) {
            Env::load();
        }
        return $_ENV['ENV'] ?? 'prod';
    }

    /**
     * Retourne le nom de la table principale des données capteurs
     *
     * @return string 'ffp3Data' en prod, 'ffp3Data2' en test, 'ffp3Data3' en test3
     */
    public static function getDataTable(): string
    {
        return match (self::getEnvironment()) {
            'test' => 'ffp3Data2',
            'test3' => 'ffp3Data3',
            's3' => 'ffp3Data4',
            default => 'ffp3Data',
        };
    }

    /**
     * Retourne le nom de la table des outputs (GPIO/relais)
     *
     * @return string 'ffp3Outputs' en prod, 'ffp3Outputs2' en test, 'ffp3Outputs3' en test3
     */
    public static function getOutputsTable(): string
    {
        return match (self::getEnvironment()) {
            'test' => 'ffp3Outputs2',
            'test3' => 'ffp3Outputs3',
            's3' => 'ffp3Outputs4',
            default => 'ffp3Outputs',
        };
    }

    public static function getOutputsTableFor(string $environment): string
    {
        return match ($environment) {
            'test' => 'ffp3Outputs2',
            'test3' => 'ffp3Outputs3',
            's3' => 'ffp3Outputs4',
            default => 'ffp3Outputs',
        };
    }

    /**
     * Retourne l'identifiant de la board utilisée pour updateLastRequest (PostData)
     * Chaque environnement pointe vers une board distincte dans la table Boards partagée.
     *
     * @return string '1' en prod, '1' en test, '4' en test3
     */
    public static function getPostDataBoardId(): string
    {
        return match (self::getEnvironment()) {
            'test3' => '4',
            's3' => '5',
            default => '1',
        };
    }

    /**
     * Retourne le nom de la table heartbeat ESP32
     *
     * @return string 'ffp3Heartbeat' en prod, 'ffp3Heartbeat2' en test, 'ffp3Heartbeat3' en test3
     */
    public static function getHeartbeatTable(): string
    {
        return match (self::getEnvironment()) {
            'test' => 'ffp3Heartbeat2',
            'test3' => 'ffp3Heartbeat3',
            's3' => 'ffp3Heartbeat4',
            default => 'ffp3Heartbeat',
        };
    }

    /**
     * Force un environnement spécifique (utile pour les routes)
     *
     * @param string $env 'prod', 'test', 'test3' ou 's3'
     */
    public static function setEnvironment(string $env): void
    {
        if (!in_array($env, self::ENVIRONMENTS, true)) {
            throw new \InvalidArgumentException("Environment must be 'prod', 'test', 'test3' or 's3', got: {$env}");
        }
        $_ENV['ENV'] = $env;
    }
}

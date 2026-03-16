<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Classe de configuration des noms de tables selon l'environnement
 *
 * Permet de basculer entre les tables de production (ffp3Data, ffp3Outputs),
 * les tables de test (ffp3Data2, ffp3Outputs2, ffp3Data3, ffp3Outputs3)
 * et les tables S3 dédiées (ffp3DataS3 / ffp3OutputsS3 en prod, ffp3DataS3Test / ffp3OutputsS3Test en test).
 */
class TableConfig
{
    private const ENVIRONMENTS = ['prod', 'test', 'test3', 's3', 's3test', 'n3pp_test', 'msp_test'];

    /**
     * Détermine si on est en environnement de test (test, test3 ou s3test)
     * s3 est du prod, donc isTest() retourne false pour s3
     */
    public static function isTest(): bool
    {
        if (!isset($_ENV['ENV'])) {
            Env::load();
        }
        $env = $_ENV['ENV'] ?? 'prod';
        return in_array($env, ['test', 'test3', 's3test', 'n3pp_test', 'msp_test'], true);
    }

    /**
     * Retourne l'environnement actuel (prod, test, test3, s3 ou s3test)
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
     * @return string 'ffp3Data' en prod, 'ffp3Data2' en test, 'ffp3Data3' en test3, 'ffp3DataS3' en s3, 'ffp3DataS3Test' en s3test
     */
    public static function getDataTable(): string
    {
        return match (self::getEnvironment()) {
            'test' => 'ffp3Data2',
            'test3' => 'ffp3Data3',
            's3' => 'ffp3DataS3',
            's3test' => 'ffp3DataS3Test',
            default => 'ffp3Data',
        };
    }

    /**
     * Retourne le nom de la table des outputs (GPIO/relais)
     *
     * @return string 'ffp3Outputs' en prod, 'ffp3Outputs2' en test, 'ffp3Outputs3' en test3, 'ffp3OutputsS3' en s3, 'ffp3OutputsS3Test' en s3test
     */
    public static function getOutputsTable(): string
    {
        return match (self::getEnvironment()) {
            'test' => 'ffp3Outputs2',
            'test3' => 'ffp3Outputs3',
            's3' => 'ffp3OutputsS3',
            's3test' => 'ffp3OutputsS3Test',
            default => 'ffp3Outputs',
        };
    }

    public static function getOutputsTableFor(string $environment): string
    {
        return match ($environment) {
            'test' => 'ffp3Outputs2',
            'test3' => 'ffp3Outputs3',
            's3' => 'ffp3OutputsS3',
            's3test' => 'ffp3OutputsS3Test',
            default => 'ffp3Outputs',
        };
    }

    /**
     * Retourne l'identifiant de la board utilisée pour updateLastRequest (PostData)
     * Chaque environnement pointe vers une board distincte dans la table Boards partagée.
     *
     * @return string '1' en prod, '4' en test3, '5' en s3, '6' en s3test
     */
    public static function getPostDataBoardId(): string
    {
        return match (self::getEnvironment()) {
            'test3' => '4',
            's3' => '5',
            's3test' => '6',
            default => '1',
        };
    }

    /**
     * Retourne le nom de la table heartbeat ESP32
     *
     * @return string 'ffp3Heartbeat' en prod, 'ffp3Heartbeat2' en test, 'ffp3Heartbeat3' en test3, 'ffp3HeartbeatS3' en s3, 'ffp3HeartbeatS3Test' en s3test
     */
    public static function getHeartbeatTable(): string
    {
        return match (self::getEnvironment()) {
            'test' => 'ffp3Heartbeat2',
            'test3' => 'ffp3Heartbeat3',
            's3' => 'ffp3HeartbeatS3',
            's3test' => 'ffp3HeartbeatS3Test',
            default => 'ffp3Heartbeat',
        };
    }

    // ── Tables N3PP (serre / elevage) ─────────────────────────────────

    public static function getN3ppDataTable(): string
    {
        return self::getEnvironment() === 'n3pp_test' ? 'n3ppDataTest' : 'n3ppData';
    }

    public static function getN3ppOutputsTable(): string
    {
        return self::getEnvironment() === 'n3pp_test' ? 'n3ppOutputsTest' : 'n3ppOutputs';
    }

    // ── Tables MSP1 (station meteo) ─────────────────────────────────

    public static function getMspDataTable(): string
    {
        return self::getEnvironment() === 'msp_test' ? 'msp1DataTest' : 'msp1Data';
    }

    public static function getMspOutputsTable(): string
    {
        return self::getEnvironment() === 'msp_test' ? 'msp1OutputsTest' : 'msp1Outputs';
    }

    /**
     * Force un environnement spécifique (utile pour les routes)
     */
    public static function setEnvironment(string $env): void
    {
        if (!in_array($env, self::ENVIRONMENTS, true)) {
            throw new \InvalidArgumentException(
                "Environment must be one of: " . implode(', ', self::ENVIRONMENTS) . ", got: {$env}"
            );
        }
        $_ENV['ENV'] = $env;
    }
}

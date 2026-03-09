<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Helper pour les URLs de l'API temps réel et contrôle (tous modules).
 */
final class RealtimeUrlHelper
{
    /**
     * Retourne le préfixe de base pour l'API realtime FFP3 selon l'environnement.
     */
    public static function getRealtimeApiBase(string $environment): string
    {
        return match ($environment) {
            'test' => '/api/realtime-test',
            'test3' => '/api/realtime3-test',
            's3' => '/api/realtime3',
            default => '/api/realtime',
        };
    }

    /**
     * Retourne le préfixe de base pour l'API outputs FFP3 selon l'environnement.
     */
    public static function getOutputsApiBase(string $environment): string
    {
        return match ($environment) {
            'test' => '/api/outputs-test',
            'test3' => '/api/outputs3-test',
            's3' => '/api/outputs3',
            default => '/api/outputs',
        };
    }

    /**
     * Retourne le préfixe de base pour l'API realtime MSP1 selon l'environnement.
     */
    public static function getMspRealtimeApiBase(string $environment): string
    {
        return match ($environment) {
            'msp_test' => '/msp1-test/api/realtime',
            default => '/msp1/api/realtime',
        };
    }

    /**
     * Retourne le préfixe de base pour l'API realtime N3PP selon l'environnement.
     */
    public static function getN3ppRealtimeApiBase(string $environment): string
    {
        return match ($environment) {
            'n3pp_test' => '/n3pp-test/api/realtime',
            default => '/n3pp/api/realtime',
        };
    }

    /**
     * Retourne le préfixe de base pour l'API realtime d'un module donné.
     *
     * @param string $module 'ffp3', 'msp1' ou 'n3pp'
     */
    public static function getModuleRealtimeApiBase(string $module, string $environment): string
    {
        return match ($module) {
            'msp1' => self::getMspRealtimeApiBase($environment),
            'n3pp' => self::getN3ppRealtimeApiBase($environment),
            default => self::getRealtimeApiBase($environment),
        };
    }
}

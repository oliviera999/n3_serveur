<?php

declare(strict_types=1);

namespace App\Mcp\Support;

use App\Config\TableConfig;

/**
 * Bascule d'environnement BDD le temps d'un appel d'outil, avec remise en état GARANTIE.
 *
 * Passe exclusivement par {@see TableConfig::setEnvironment()} (jamais de mutation de
 * `$_ENV`) et restaure l'environnement précédent dans un `finally`, même si l'outil lève.
 */
final class EnvironmentScope
{
    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function run(string $env, callable $callback): mixed
    {
        $previous = TableConfig::getEnvironment();
        $default = TableConfig::getDefaultEnvironment();

        TableConfig::setEnvironment($env);
        try {
            return $callback();
        } finally {
            if ($previous === $default) {
                TableConfig::resetRequestEnvironment();
            } else {
                TableConfig::setEnvironment($previous);
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Source unique de vérité du mapping GPIO ↔ paramètre pour le module N3PP
 * (serre / aquaponie).
 *
 * Avant : le mapping (GPIO de configuration → nom de paramètre) était figé en
 * dur dans `N3ppOutputRepository::PARAM_GPIO_MAP`, dupliquant la connaissance du
 * couplage GPIO ↔ paramètre au sein du repository.
 *
 * Après : tout dérive d'ici.
 *  - {@see paramGpioMap()}     GPIO → nom de paramètre (source canonique, utilisé
 *                              par N3ppOutputRepository::getParamGpioMap()).
 *  - {@see parameterGpioMap()} nom de paramètre → GPIO (dérivé, inverse).
 *
 * Les valeurs sont strictement préservées : mêmes GPIO, mêmes noms, mêmes clés
 * spécifiques module (notamment 104 = HeureArrosage / 105 = tempsArrosage,
 * distinctes de MSP).
 */
final class N3ppGpioMap
{
    /**
     * Mapping GPIO 100-107 → noms de paramètres (site initial).
     *
     * @var array<int, string>
     */
    private const PARAM_GPIO_MAP = [
        100 => 'mail',
        101 => 'mailNotif',
        102 => 'SeuilSec',
        103 => 'SeuilPontDiv',
        104 => 'HeureArrosage',
        105 => 'tempsArrosage',
        106 => 'WakeUp',
        107 => 'FreqWakeUp',
    ];

    /**
     * GPIO → nom de paramètre (source canonique).
     *
     * @return array<int, string>
     */
    public static function paramGpioMap(): array
    {
        return self::PARAM_GPIO_MAP;
    }

    /**
     * Nom de paramètre → GPIO, dérivé de la source canonique.
     *
     * @return array<string, int>
     */
    public static function parameterGpioMap(): array
    {
        return array_flip(self::PARAM_GPIO_MAP);
    }
}

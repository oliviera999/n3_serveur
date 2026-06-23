<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Validation des angles servo de nourrissage FFP3 (GPIO 118-123).
 */
final class FeedingServoAngleValidator
{
    public const MIN_ANGLE = 0;
    public const MAX_ANGLE = 180;

  /** @var list<string> */
    private const ANGLE_PARAM_NAMES = [
        'angleReposGros',
        'angleDistribGros',
        'angleInterGros',
        'angleReposPetits',
        'angleDistribPetits',
        'angleInterPetits',
    ];

    /**
     * Valide les paramètres d'angle présents dans le payload.
     *
     * @param array<string, mixed> $params
     * @throws \InvalidArgumentException
     */
    public static function assertAngleRanges(array $params): void
    {
        foreach (self::ANGLE_PARAM_NAMES as $name) {
            if (!array_key_exists($name, $params)) {
                continue;
            }
            $value = $params[$name];
            if ($value === null || $value === '') {
                continue;
            }
            if (!is_numeric($value)) {
                throw new \InvalidArgumentException(
                    sprintf('%s doit être un entier entre %d et %d', $name, self::MIN_ANGLE, self::MAX_ANGLE)
                );
            }
            $angle = (int) round((float) $value);
            if ($angle < self::MIN_ANGLE || $angle > self::MAX_ANGLE) {
                throw new \InvalidArgumentException(
                    sprintf('%s hors limites (%d-%d)', $name, self::MIN_ANGLE, self::MAX_ANGLE)
                );
            }
        }
    }
}

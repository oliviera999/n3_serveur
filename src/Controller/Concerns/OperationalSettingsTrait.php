<?php

declare(strict_types=1);

namespace App\Controller\Concerns;

use App\Service\OperationalSettingsService;

/**
 * Accès aux réglages opérationnels (BDD > .env) avec repli direct .env si service absent.
 */
trait OperationalSettingsTrait
{
    protected ?OperationalSettingsService $operationalSettings = null;

    protected function opBool(string $envKey, bool $default): bool
    {
        return $this->operationalSettings?->bool($envKey, $default)
            ?? filter_var($_ENV[$envKey] ?? ($default ? 'true' : 'false'), FILTER_VALIDATE_BOOLEAN);
    }

    protected function opInt(string $envKey, int $default): int
    {
        if ($this->operationalSettings !== null) {
            return $this->operationalSettings->int($envKey, $default);
        }
        if (isset($_ENV[$envKey]) && is_numeric($_ENV[$envKey])) {
            return (int) $_ENV[$envKey];
        }

        return $default;
    }

    protected function opFloat(string $envKey, float $default): float
    {
        if ($this->operationalSettings !== null) {
            return $this->operationalSettings->float($envKey, $default);
        }
        if (isset($_ENV[$envKey]) && is_numeric($_ENV[$envKey])) {
            return (float) $_ENV[$envKey];
        }

        return $default;
    }

    protected function opOptionalFloat(string $envKey): ?float
    {
        if ($this->operationalSettings !== null) {
            return $this->operationalSettings->optionalFloat($envKey);
        }
        if (!isset($_ENV[$envKey]) || (string) $_ENV[$envKey] === '' || !is_numeric($_ENV[$envKey])) {
            return null;
        }

        return (float) $_ENV[$envKey];
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\OperationalSettingsCatalog;
use App\Repository\ServerSettingsRepository;
use InvalidArgumentException;

/**
 * Résolution des réglages opérationnels : override BDD (`op_<ENV_KEY>`) puis `.env`.
 */
final class OperationalSettingsService
{
    private const OFF_MARKER = '__OFF__';

    public function __construct(
        private readonly ServerSettingsRepository $settings,
    ) {
    }

    public function bool(string $envKey, bool $default): bool
    {
        $def = OperationalSettingsCatalog::get($envKey);
        if ($def !== null && is_bool($def['default'])) {
            $default = $def['default'];
        }

        $dbKey = OperationalSettingsCatalog::dbKey($envKey);
        if ($this->settings->hasKey($dbKey)) {
            return $this->settings->getBool($dbKey, $default);
        }

        return $this->envBool($envKey, $default);
    }

    public function int(string $envKey, int $default): int
    {
        $resolved = $this->resolveScalar($envKey, $default);
        if ($resolved === null || $resolved === self::OFF_MARKER) {
            return $default;
        }

        return (int) $resolved;
    }

    public function float(string $envKey, float $default): float
    {
        $resolved = $this->resolveScalar($envKey, $default);
        if ($resolved === null || $resolved === self::OFF_MARKER) {
            return $default;
        }

        return (float) $resolved;
    }

    public function string(string $envKey, string $default = ''): string
    {
        $resolved = $this->resolveScalar($envKey, $default);
        if ($resolved === null || $resolved === self::OFF_MARKER) {
            return $default;
        }

        return (string) $resolved;
    }

    public function optionalInt(string $envKey): ?int
    {
        $resolved = $this->resolveOptional($envKey);
        if ($resolved === null) {
            return null;
        }

        return (int) $resolved;
    }

    public function optionalFloat(string $envKey): ?float
    {
        $resolved = $this->resolveOptional($envKey);
        if ($resolved === null) {
            return null;
        }

        return (float) $resolved;
    }

    /**
     * @param mixed $value null = réinitialiser (repli .env), '' pour optional = désactivé
     */
    public function set(string $envKey, mixed $value, ?string $updatedBy): void
    {
        $def = OperationalSettingsCatalog::get($envKey);
        if ($def === null) {
            throw new InvalidArgumentException('Réglage inconnu : ' . $envKey);
        }

        $dbKey = OperationalSettingsCatalog::dbKey($envKey);
        $type = $def['type'];

        if ($value === null) {
            $this->settings->deleteKey($dbKey);

            return;
        }

        if ($type === OperationalSettingsCatalog::TYPE_OPTIONAL_INT
            || $type === OperationalSettingsCatalog::TYPE_OPTIONAL_FLOAT) {
            if ($value === '' || $value === false) {
                $this->settings->setRaw($dbKey, self::OFF_MARKER, $updatedBy);

                return;
            }
        }

        $normalized = $this->normalizeForStorage($envKey, $value);
        $this->settings->setRaw($dbKey, $normalized, $updatedBy);
    }

    public function reset(string $envKey): void
    {
        $this->settings->deleteKey(OperationalSettingsCatalog::dbKey($envKey));
    }

    /**
     * @param list<string> $envKeys
     */
    public function resetMany(array $envKeys): void
    {
        foreach ($envKeys as $key) {
            $this->reset($key);
        }
    }

    public function resetGroup(string $group): void
    {
        foreach (OperationalSettingsCatalog::all() as $envKey => $def) {
            if ($def['group'] === $group) {
                $this->reset($envKey);
            }
        }
    }

    /**
     * @return array{
     *   groups: array<string, string>,
     *   settings: list<array<string, mixed>>
     * }
     */
    public function exportForUi(): array
    {
        $items = [];
        foreach (OperationalSettingsCatalog::all() as $envKey => $def) {
            $items[] = $this->exportOne($envKey, $def);
        }

        return [
            'groups' => OperationalSettingsCatalog::groupLabels(),
            'settings' => $items,
        ];
    }

    /**
     * @param array<string, mixed> $def
     *
     * @return array<string, mixed>
     */
    private function exportOne(string $envKey, array $def): array
    {
        $dbKey = OperationalSettingsCatalog::dbKey($envKey);
        $hasOverride = $this->settings->hasKey($dbKey);
        $envValue = $this->rawEnv($envKey);
        $effective = $this->effectiveValue($envKey, $def);

        return [
            'env_key' => $envKey,
            'group' => $def['group'],
            'type' => $def['type'],
            'label' => $def['label'],
            'hint' => $def['hint'],
            'unit' => $def['unit'] ?? null,
            'min' => $def['min'] ?? null,
            'max' => $def['max'] ?? null,
            'options' => $def['options'] ?? null,
            'env_default' => $envValue ?? $this->formatDefault($def),
            'effective' => $effective,
            'source' => $hasOverride ? 'database' : 'env',
        ];
    }

    /**
     * @param array<string, mixed> $def
     */
    private function effectiveValue(string $envKey, array $def): mixed
    {
        return match ($def['type']) {
            OperationalSettingsCatalog::TYPE_BOOL => $this->bool($envKey, (bool) ($def['default'] ?? false)),
            OperationalSettingsCatalog::TYPE_INT => $this->int($envKey, (int) ($def['default'] ?? 0)),
            OperationalSettingsCatalog::TYPE_FLOAT => $this->float($envKey, (float) ($def['default'] ?? 0.0)),
            OperationalSettingsCatalog::TYPE_STRING,
            OperationalSettingsCatalog::TYPE_ENUM => $this->string($envKey, (string) ($def['default'] ?? '')),
            OperationalSettingsCatalog::TYPE_OPTIONAL_INT => $this->optionalInt($envKey),
            OperationalSettingsCatalog::TYPE_OPTIONAL_FLOAT => $this->optionalFloat($envKey),
            default => null,
        };
    }

    private function resolveScalar(string $envKey, mixed $default): ?string
    {
        $dbKey = OperationalSettingsCatalog::dbKey($envKey);
        if ($this->settings->hasKey($dbKey)) {
            return $this->settings->getRaw($dbKey);
        }

        $env = $this->rawEnv($envKey);
        if ($env !== null && $env !== '') {
            return $env;
        }

        if ($default === null) {
            return null;
        }

        return (string) $default;
    }

    private function resolveOptional(string $envKey): ?string
    {
        $dbKey = OperationalSettingsCatalog::dbKey($envKey);
        if ($this->settings->hasKey($dbKey)) {
            $raw = $this->settings->getRaw($dbKey);
            if ($raw === null || $raw === '' || $raw === self::OFF_MARKER) {
                return null;
            }

            return $raw;
        }

        $env = $this->rawEnv($envKey);
        if ($env === null || $env === '' || !is_numeric($env)) {
            return null;
        }

        return $env;
    }

    private function rawEnv(string $envKey): ?string
    {
        if (!isset($_ENV[$envKey])) {
            return null;
        }
        $v = trim((string) $_ENV[$envKey]);

        return $v === '' ? null : $v;
    }

    private function envBool(string $envKey, bool $default): bool
    {
        $raw = $this->rawEnv($envKey);
        if ($raw === null) {
            return $default;
        }

        return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param array<string, mixed> $def
     */
    private function formatDefault(array $def): mixed
    {
        return $def['default'];
    }

    private function normalizeForStorage(string $envKey, mixed $value): string
    {
        $def = OperationalSettingsCatalog::get($envKey);
        if ($def === null) {
            throw new InvalidArgumentException('Réglage inconnu');
        }

        $type = $def['type'];

        if ($type === OperationalSettingsCatalog::TYPE_BOOL) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        }

        if ($type === OperationalSettingsCatalog::TYPE_ENUM) {
            $str = strtoupper(trim((string) $value));
            $options = $def['options'] ?? [];
            $allowed = array_map(static fn (string $o): string => strtoupper($o), $options);
            if (!in_array($str, $allowed, true)) {
                throw new InvalidArgumentException('Valeur enum invalide pour ' . $envKey);
            }
            // NOTIF_MODE en minuscules
            if ($envKey === 'NOTIF_MODE') {
                return strtolower($str);
            }

            return $str;
        }

        if ($type === OperationalSettingsCatalog::TYPE_INT
            || $type === OperationalSettingsCatalog::TYPE_OPTIONAL_INT) {
            if (!is_numeric($value)) {
                throw new InvalidArgumentException('Entier attendu pour ' . $envKey);
            }
            $int = (int) $value;
            $this->assertRange($def, (float) $int);

            return (string) $int;
        }

        if ($type === OperationalSettingsCatalog::TYPE_FLOAT
            || $type === OperationalSettingsCatalog::TYPE_OPTIONAL_FLOAT) {
            if (!is_numeric($value)) {
                throw new InvalidArgumentException('Nombre attendu pour ' . $envKey);
            }
            $float = (float) $value;
            $this->assertRange($def, $float);

            return (string) $float;
        }

        return trim((string) $value);
    }

    /**
     * @param array<string, mixed> $def
     */
    private function assertRange(array $def, float $value): void
    {
        if (isset($def['min']) && $value < (float) $def['min']) {
            throw new InvalidArgumentException('Valeur trop basse');
        }
        if (isset($def['max']) && $value > (float) $def['max']) {
            throw new InvalidArgumentException('Valeur trop haute');
        }
    }
}

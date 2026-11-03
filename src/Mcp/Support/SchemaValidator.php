<?php

declare(strict_types=1);

namespace App\Mcp\Support;

/**
 * Validateur JSON Schema minimal couvrant le sous-ensemble utilisé par nos `inputSchema` :
 * `type` (object, string, integer, number, boolean, array), `properties`, `required`,
 * `additionalProperties: false`, `enum`, `minimum`/`maximum`, `minLength`/`maxLength`,
 * `pattern`, `items`, `minItems`/`maxItems`, `uniqueItems`, `default`.
 *
 * Choix : une dépendance complète (opis/json-schema) n'apporte rien pour ces schémas
 * fermés et alourdirait le déploiement mutualisé. Les schémas sont des constantes du
 * code, jamais fournis par l'appelant : seul le sous-ensemble ci-dessus est supporté.
 */
final class SchemaValidator
{
    /**
     * Valide et normalise les arguments (valeurs par défaut appliquées au niveau racine).
     *
     * @param array<string, mixed> $schema
     * @param mixed                $value
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException message en français, chemin de l'erreur inclus
     */
    public static function validateArguments(array $schema, mixed $value): array
    {
        if ($value === null || $value instanceof \stdClass) {
            $value = $value === null ? [] : (array) json_decode((string) json_encode($value), true);
        }
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new \InvalidArgumentException('Les arguments doivent être un objet JSON.');
        }

        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        foreach ($properties as $name => $propSchema) {
            if (!array_key_exists($name, $value) && is_array($propSchema) && array_key_exists('default', $propSchema)) {
                $value[$name] = $propSchema['default'];
            }
        }

        self::validate($schema, $value, 'arguments');

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function validate(array $schema, mixed $value, string $path): void
    {
        $type = $schema['type'] ?? null;
        if (is_string($type)) {
            self::checkType($type, $value, $path);
        }

        if (isset($schema['enum']) && is_array($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            throw new \InvalidArgumentException(sprintf(
                '%s : valeur non autorisée (%s). Valeurs possibles : %s.',
                $path,
                self::describe($value),
                implode(', ', array_map([self::class, 'describe'], $schema['enum']))
            ));
        }

        if (is_int($value) || is_float($value)) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                throw new \InvalidArgumentException(sprintf('%s : doit être ≥ %s.', $path, (string) $schema['minimum']));
            }
            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                throw new \InvalidArgumentException(sprintf('%s : doit être ≤ %s.', $path, (string) $schema['maximum']));
            }
        }

        if (is_string($value)) {
            $length = mb_strlen($value);
            if (isset($schema['minLength']) && $length < (int) $schema['minLength']) {
                throw new \InvalidArgumentException(sprintf('%s : trop court (min %d).', $path, (int) $schema['minLength']));
            }
            if (isset($schema['maxLength']) && $length > (int) $schema['maxLength']) {
                throw new \InvalidArgumentException(sprintf('%s : trop long (max %d).', $path, (int) $schema['maxLength']));
            }
            if (isset($schema['pattern']) && is_string($schema['pattern'])
                && preg_match('/' . str_replace('/', '\/', $schema['pattern']) . '/u', $value) !== 1) {
                throw new \InvalidArgumentException(sprintf('%s : format invalide.', $path));
            }
        }

        if ($type === 'object' && is_array($value)) {
            $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
            foreach ((array) ($schema['required'] ?? []) as $required) {
                if (!array_key_exists((string) $required, $value)) {
                    throw new \InvalidArgumentException(sprintf('%s.%s : champ requis manquant.', $path, (string) $required));
                }
            }
            foreach ($value as $key => $item) {
                $key = (string) $key;
                if (!isset($properties[$key])) {
                    if (($schema['additionalProperties'] ?? true) === false) {
                        throw new \InvalidArgumentException(sprintf(
                            '%s : propriété inconnue « %s ». Propriétés acceptées : %s.',
                            $path,
                            $key,
                            $properties === [] ? '(aucune)' : implode(', ', array_keys($properties))
                        ));
                    }
                    continue;
                }
                if (is_array($properties[$key])) {
                    self::validate($properties[$key], $item, $path . '.' . $key);
                }
            }
        }

        if ($type === 'array' && is_array($value)) {
            $count = count($value);
            if (isset($schema['minItems']) && $count < (int) $schema['minItems']) {
                throw new \InvalidArgumentException(sprintf('%s : au moins %d élément(s).', $path, (int) $schema['minItems']));
            }
            if (isset($schema['maxItems']) && $count > (int) $schema['maxItems']) {
                throw new \InvalidArgumentException(sprintf('%s : au plus %d élément(s).', $path, (int) $schema['maxItems']));
            }
            if (($schema['uniqueItems'] ?? false) === true && count(array_unique(array_map('serialize', $value))) !== $count) {
                throw new \InvalidArgumentException(sprintf('%s : éléments en double.', $path));
            }
            if (isset($schema['items']) && is_array($schema['items'])) {
                foreach ($value as $index => $item) {
                    self::validate($schema['items'], $item, $path . '[' . $index . ']');
                }
            }
        }
    }

    private static function checkType(string $type, mixed $value, string $path): void
    {
        $ok = match ($type) {
            'object' => is_array($value) && ($value === [] || !array_is_list($value)),
            'array' => is_array($value) && array_is_list($value),
            'string' => is_string($value),
            'integer' => is_int($value) || (is_float($value) && floor($value) === $value && abs($value) < PHP_INT_MAX),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'null' => $value === null,
            default => true,
        };
        if (!$ok) {
            throw new \InvalidArgumentException(sprintf('%s : type attendu %s, reçu %s.', $path, $type, get_debug_type($value)));
        }
    }

    private static function describe(mixed $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? '?' : $json;
    }
}

<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Reconstruit le corps POST form-urlencoded signé par FFP5CS (HMAC X-Sig-*)
 * quand php://input est indisponible (mod_php + application/x-www-form-urlencoded).
 *
 * Ordre aligné sur automatism_sync.cpp (full update) et web_client.cpp sendMeasurements.
 */
final class Ffp3HmacPostBody
{
    /** Champs auth legacy / HMAC dans le corps — exclus de la reconstruction. */
    private const AUTH_KEYS = ['timestamp', 'signature'];

    /** Full update (automatism_sync.cpp snprintf + suffixes). */
    private const FULL_UPDATE_KEYS = [
        'api_key', 'sensor', 'version',
        'TempAir', 'Humidite', 'Pression', 'TempEau',
        'EauPotager', 'EauAquarium', 'EauReserve', 'diffMaree', 'Luminosite',
        'etatPompeAqua', 'etatPompeTank', 'etatHeat', 'etatUV',
        'bouffeMatin', 'bouffeMidi', 'bouffeSoir', 'tempsGros', 'tempsPetits',
        'aqThreshold', 'tankThreshold', 'chauffageThreshold',
        'tempsRemplissageSec', 'limFlood', 'WakeUp', 'FreqWakeUp',
        'bouffePetits', 'bouffeGros', 'mail', 'mailNotif',
    ];

    private const SUFFIX_KEYS = [
        'resetMode', 'tideEvent', 'tideTrend', 'tideNoiseMm', 'tideWindowMs', 'tideExtremeMm',
        'configSynced', 'post_id',
    ];

    /** Mesures simples (web_client sendMeasurements + postRaw prefix). */
    private const MEASUREMENT_KEYS = [
        'api_key', 'sensor', 'version',
        'TempAir', 'Humidite', 'TempEau',
        'EauPotager', 'EauAquarium', 'EauReserve', 'diffMaree', 'Luminosite',
        'etatPompeAqua', 'etatPompeTank', 'etatHeat', 'etatUV', 'resetMode',
    ];

    private const FLOAT_ONE_DECIMAL = [
        'TempAir', 'Humidite', 'Pression', 'TempEau', 'chauffageThreshold',
    ];

    /**
     * @param array<string, mixed> $params Paramètres POST déjà parsés ($_POST / parsedBody)
     */
    public static function buildFromParams(array $params): string
    {
        if ($params === []) {
            return '';
        }

        $isFullUpdate = isset($params['Pression']) || isset($params['bouffeMatin']);

        $orderedKeys = $isFullUpdate ? self::FULL_UPDATE_KEYS : self::MEASUREMENT_KEYS;
        $parts = [];

        foreach ($orderedKeys as $key) {
            if (!isset($params[$key]) || !is_scalar($params[$key])) {
                continue;
            }
            $parts[] = self::formatPair($key, $params[$key]);
        }

        if ($isFullUpdate) {
            $known = array_merge(self::FULL_UPDATE_KEYS, self::SUFFIX_KEYS, self::AUTH_KEYS);
            $extra = self::collectExtraKeys($params, $known);
            foreach ($extra as $key) {
                $parts[] = self::formatPair($key, $params[$key]);
            }
            foreach (self::SUFFIX_KEYS as $key) {
                if (!isset($params[$key]) || !is_scalar($params[$key])) {
                    continue;
                }
                $parts[] = self::formatPair($key, $params[$key]);
            }
        } else {
            $known = array_merge(self::MEASUREMENT_KEYS, self::AUTH_KEYS);
            $extra = self::collectExtraKeys($params, $known);
            foreach ($extra as $key) {
                $parts[] = self::formatPair($key, $params[$key]);
            }
        }

        return implode('&', $parts);
    }

    /**
     * @param array<string, mixed> $params
     * @param list<string> $known
     * @return list<string>
     */
    private static function collectExtraKeys(array $params, array $known): array
    {
        $knownSet = array_flip($known);
        $extra = [];
        foreach (array_keys($params) as $key) {
            $keyStr = (string) $key;
            if (!isset($knownSet[$keyStr]) && is_scalar($params[$key])) {
                $extra[] = $keyStr;
            }
        }
        sort($extra, SORT_STRING);

        return $extra;
    }

    private static function formatPair(string $key, mixed $value): string
    {
        $s = trim((string) $value);

        if (in_array($key, self::FLOAT_ONE_DECIMAL, true) && is_numeric($s)) {
            return $key . '=' . sprintf('%.1f', (float) $s);
        }

        if (in_array($key, ['diffMaree', 'etatPompeAqua', 'etatPompeTank', 'etatHeat', 'etatUV', 'WakeUp', 'resetMode', 'configSynced', 'tideTrend'], true)
            && is_numeric($s)) {
            return $key . '=' . (string) (int) $s;
        }

        if (in_array($key, [
            'EauPotager', 'EauAquarium', 'EauReserve', 'Luminosite',
            'bouffeMatin', 'bouffeMidi', 'bouffeSoir', 'tempsGros', 'tempsPetits',
            'aqThreshold', 'tankThreshold', 'tempsRemplissageSec', 'limFlood', 'FreqWakeUp',
            'tideNoiseMm', 'tideExtremeMm',
        ], true) && is_numeric($s)) {
            return $key . '=' . (string) (int) $s;
        }

        if ($key === 'tideWindowMs' && is_numeric($s)) {
            return $key . '=' . (string) (int) $s;
        }

        return $key . '=' . $s;
    }
}

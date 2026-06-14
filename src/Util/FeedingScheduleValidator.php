<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Validation des horaires de nourrissage (créneaux matin / midi / soir).
 *
 * Miroir côté serveur de la logique embarquée (firmware `FeedingSlotMatcher::isScheduleConfigValid`) :
 * - chaque heure doit être un entier dans [0..23] ;
 * - les trois créneaux doivent être distincts et strictement croissants (matin < midi < soir),
 *   sinon plusieurs créneaux partageant la même heure peuvent fusionner côté appareil.
 *
 * La contrainte de plage est dure (rejet). La contrainte d'ordre est consultative
 * (avertissement non bloquant) : l'interface enregistre les paramètres un par un,
 * donc une édition multi-étapes peut transiter par un état temporairement non croissant.
 */
class FeedingScheduleValidator
{
    /** Noms des paramètres d'horaire de nourrissage (ordre = matin, midi, soir). */
    public const FEEDING_HOUR_PARAMS = ['bouffeMatin', 'bouffeMidi', 'bouffeSoir'];

    private const HOUR_MIN = 0;
    private const HOUR_MAX = 23;

    /**
     * Valide la plage [0..23] de chaque horaire de nourrissage présent dans $params.
     *
     * @param array<string, mixed> $params Paramètres entrants (sous-ensemble possible)
     * @return array<string, int> Heures validées (uniquement celles présentes)
     * @throws \InvalidArgumentException Si une heure est non entière ou hors de [0..23]
     */
    public static function assertHourRanges(array $params): array
    {
        $validated = [];
        foreach (self::FEEDING_HOUR_PARAMS as $param) {
            if (!array_key_exists($param, $params)) {
                continue;
            }
            $raw = $params[$param];
            if (!is_numeric($raw)) {
                throw new \InvalidArgumentException(
                    sprintf('Horaire %s invalide : valeur non numérique.', $param)
                );
            }
            $hour = (int) $raw;
            // Rejeter les valeurs non entières (ex. "8.5") et hors plage
            if ((float) $raw !== (float) $hour || $hour < self::HOUR_MIN || $hour > self::HOUR_MAX) {
                throw new \InvalidArgumentException(
                    sprintf('Horaire %s hors plage : un entier entre 0 et 23 est attendu (reçu « %s »).', $param, (string) $raw)
                );
            }
            $validated[$param] = $hour;
        }

        return $validated;
    }

    /**
     * Vrai si matin < midi < soir (distinct + strictement croissant).
     */
    public static function isStrictlyIncreasing(int $morningHour, int $noonHour, int $eveningHour): bool
    {
        return $morningHour < $noonHour && $noonHour < $eveningHour;
    }

    /**
     * Construit un avertissement si le trio d'horaires résultant n'est pas strictement croissant.
     *
     * Le trio résultant est calculé en fusionnant les heures entrantes ($incoming) par-dessus
     * les valeurs actuellement persistées ($current). Si l'une des trois est manquante/illisible,
     * aucun avertissement n'est émis (trio incomplet).
     *
     * @param array<string, mixed> $current  Valeurs actuelles persistées (issues de getParametersMap)
     * @param array<string, int>   $incoming  Heures entrantes déjà validées (assertHourRanges)
     * @return string|null Message d'avertissement, ou null si le trio est valide ou incomplet
     */
    public static function crossFieldWarning(array $current, array $incoming): ?string
    {
        $hours = [];
        foreach (self::FEEDING_HOUR_PARAMS as $param) {
            $value = $incoming[$param] ?? $current[$param] ?? null;
            if (!is_numeric($value)) {
                return null; // trio incomplet : on ne peut pas se prononcer
            }
            $hours[$param] = (int) $value;
        }

        if (self::isStrictlyIncreasing($hours['bouffeMatin'], $hours['bouffeMidi'], $hours['bouffeSoir'])) {
            return null;
        }

        return sprintf(
            'Horaires de nourrissage non strictement croissants (matin=%dh, midi=%dh, soir=%dh) : '
            . 'ils doivent être distincts et croissants (matin < midi < soir), '
            . 'sinon plusieurs créneaux peuvent fusionner côté appareil.',
            $hours['bouffeMatin'],
            $hours['bouffeMidi'],
            $hours['bouffeSoir']
        );
    }
}

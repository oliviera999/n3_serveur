<?php

declare(strict_types=1);

namespace App\Service\Availability;

/**
 * Modèle de RÉVEIL ATTENDU d'un module en veille (remplace le seuil fixe « hors ligne »).
 *
 * Les modules dorment `FreqWakeUp` secondes puis émettent une RAFALE de requêtes (POST toutes
 * les 30 s pendant quelques minutes, un ou deux heartbeats), puis se rendorment. Un seuil fixe
 * en secondes ne sait pas dire « il a manqué deux réveils » ; il bascule aussi au gré de l'heure
 * (régime nuit) sans qu'aucune donnée n'arrive — c'est ce qui a produit le faux « Réception des
 * données rétablie » du 06/11/2026 à 19:40.
 *
 * Le modèle regroupe les contacts en rafales (écart ≤ {@see DEFAULT_BURST_GAP_SECONDS}), mesure
 * la période médiane entre débuts de rafales, la confronte à la période configurée (FreqWakeUp
 * × facteur nuit, fournie par l'appelant) et compte les réveils attendus non honorés depuis la
 * dernière rafale. L'appareil est déclaré hors ligne à partir de `tolerance` réveils manqués
 * (défaut 2). Logique pure : aucune BDD, aucune horloge implicite (le `now` est injecté).
 *
 * Choix conservateur : la période retenue est le MAX de la période configurée et de la période
 * observée. Au passage jour → nuit la consigne s'allonge avant que l'observation ne le voie ; au
 * passage nuit → jour l'observation reste longue le temps d'un cycle (la « grâce matinale » de
 * l'ancien résolveur). Dans les deux cas on tolère plutôt que d'alerter à tort.
 */
final class WakeScheduleModel
{
    /** Deux contacts séparés d'au plus 5 min appartiennent à la même rafale. */
    public const DEFAULT_BURST_GAP_SECONDS = 300;
    /** Réveils manqués consécutifs avant de déclarer l'appareil hors ligne. */
    public const DEFAULT_TOLERANCE = 2;
    /** Marge fixe (s) : connexion WiFi, DNS, TLS, latence HTTP, gigue de l'ordonnancement CRON. */
    public const FIXED_MARGIN_SECONDS = 180;
    /** Marge proportionnelle : la veille est mesurée par un timer RTC qui dérive. */
    public const RELATIVE_MARGIN = 0.10;
    /** Bornes de la période retenue (s). */
    public const MIN_PERIOD_SECONDS = 60;
    public const MAX_PERIOD_SECONDS = 86400;
    /** Au-delà, une période observée est jugée aberrante (deux rafales séparées par une panne). */
    public const MAX_OBSERVED_RATIO = 3.0;
    /** Rafales les plus récentes servant à mesurer la période observée. */
    public const RECENT_BURSTS = 6;

    public function __construct(
        private int $burstGapSeconds = self::DEFAULT_BURST_GAP_SECONDS,
        private int $tolerance = self::DEFAULT_TOLERANCE,
    ) {
        $this->burstGapSeconds = max(30, $burstGapSeconds);
        $this->tolerance = max(1, $tolerance);
    }

    /**
     * Regroupe des horodatages (epoch, ordre quelconque) en rafales.
     *
     * @param list<int> $timestamps
     * @return list<array{start: int, end: int, count: int}> Rafales du plus ancien au plus récent
     */
    public function bursts(array $timestamps): array
    {
        $ts = array_values(array_filter(array_map('intval', $timestamps), static fn (int $t): bool => $t > 0));
        sort($ts);
        $bursts = [];
        foreach ($ts as $t) {
            $last = count($bursts) - 1;
            if ($last >= 0 && $t - $bursts[$last]['end'] <= $this->burstGapSeconds) {
                $bursts[$last]['end'] = $t;
                $bursts[$last]['count']++;
                continue;
            }
            $bursts[] = ['start' => $t, 'end' => $t, 'count' => 1];
        }

        return $bursts;
    }

    /**
     * Période médiane (s) entre débuts des rafales les plus récentes, null si < 2 rafales.
     *
     * @param list<array{start: int, end: int, count: int}> $bursts
     */
    public function observedPeriod(array $bursts): ?int
    {
        $recent = array_slice($bursts, -(self::RECENT_BURSTS + 1));
        $gaps = [];
        for ($i = 1; $i < count($recent); $i++) {
            $gaps[] = $recent[$i]['start'] - $recent[$i - 1]['start'];
        }
        if ($gaps === []) {
            return null;
        }
        sort($gaps);
        $n = count($gaps);
        $median = $n % 2 === 1 ? $gaps[intdiv($n, 2)] : intdiv($gaps[$n / 2 - 1] + $gaps[$n / 2], 2);

        return max(1, (int) $median);
    }

    /**
     * @param list<int> $contactTimestamps  Contacts observés (POST acceptés, heartbeats), epoch
     * @param int|null  $configuredPeriod   FreqWakeUp effectif (s, facteur nuit compris), null si inconnu
     * @param int|null  $now                Instant d'évaluation (défaut : maintenant)
     */
    public function assess(array $contactTimestamps, ?int $configuredPeriod, ?int $now = null): WakeAssessment
    {
        $now ??= time();
        $bursts = $this->bursts($contactTimestamps);
        $configured = $configuredPeriod !== null && $configuredPeriod > 0 ? $configuredPeriod : null;

        if ($bursts === []) {
            $period = $this->bound($configured ?? 600);

            return new WakeAssessment(null, null, 0, null, $configured, $period, $this->margin($period), null, null, 0, $this->tolerance);
        }

        $last = $bursts[count($bursts) - 1];
        $observed = $this->observedPeriod($bursts);
        $period = $this->choosePeriod($configured, $observed);
        $margin = $this->margin($period);

        // Réveils attendus depuis le début de la dernière rafale : k·période, k ≥ 1.
        // Un réveil est « manqué » si son heure + marge est passée sans nouvelle rafale.
        $lastContact = $last['end'];
        $elapsed = $now - $last['start'];
        $missed = 0;
        if ($elapsed > $period + $margin) {
            $missed = (int) floor(($elapsed - $margin) / $period);
        }
        $firstMissed = $missed > 0 ? $last['start'] + $period : null;
        $next = $last['start'] + $period;
        while ($next <= $now) {
            $next += $period;
        }

        return new WakeAssessment(
            $lastContact,
            $last['start'],
            count($bursts),
            $observed,
            $configured,
            $period,
            $margin,
            $firstMissed,
            $next,
            $missed,
            $this->tolerance
        );
    }

    /** Seuil « hors ligne » équivalent (s) pour les consommateurs qui raisonnent encore en durée. */
    public function offlineAfterSeconds(WakeAssessment $a): int
    {
        return $a->period * $a->tolerance + $a->margin;
    }

    private function choosePeriod(?int $configured, ?int $observed): int
    {
        if ($configured === null && $observed === null) {
            return $this->bound(600);
        }
        if ($observed !== null && $configured !== null && $observed > $configured * self::MAX_OBSERVED_RATIO) {
            // Rafales séparées par une panne : l'observation n'est pas une cadence, on l'ignore.
            $observed = null;
        }

        return $this->bound(max($configured ?? 0, $observed ?? 0));
    }

    private function margin(int $period): int
    {
        return self::FIXED_MARGIN_SECONDS + (int) round($period * self::RELATIVE_MARGIN);
    }

    private function bound(int $seconds): int
    {
        return max(self::MIN_PERIOD_SECONDS, min(self::MAX_PERIOD_SECONDS, $seconds));
    }
}

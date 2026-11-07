<?php

declare(strict_types=1);

namespace App\Service\Availability;

/**
 * Résultat de {@see WakeScheduleModel::assess()} : où en est l'appareil par rapport à ses
 * réveils attendus. Objet valeur immuable, sans dépendance, sérialisable en JSON (MCP).
 */
final class WakeAssessment
{
    public function __construct(
        /** Dernier contact observé (epoch), null si aucun. */
        public readonly ?int $lastContact,
        /** Début de la dernière rafale observée (epoch), null si aucune. */
        public readonly ?int $lastBurstStart,
        /** Nombre de rafales distinctes dans la fenêtre d'observation. */
        public readonly int $bursts,
        /** Période médiane observée entre deux rafales (s), null si < 2 rafales. */
        public readonly ?int $observedPeriod,
        /** Période configurée (FreqWakeUp effectif, facteur nuit compris), null si inconnue. */
        public readonly ?int $configuredPeriod,
        /** Période retenue pour le calcul (s). */
        public readonly int $period,
        /** Marge tolérée (s) autour d'un réveil attendu : latence WiFi/HTTP + gigue CRON. */
        public readonly int $margin,
        /** Premier réveil attendu non honoré (epoch), null si aucun réveil manqué. */
        public readonly ?int $firstMissedAt,
        /** Prochain réveil attendu dans le futur (epoch), null si aucun contact connu. */
        public readonly ?int $nextExpectedAt,
        /** Réveils attendus et non honorés depuis la dernière rafale. */
        public readonly int $missedWakes,
        /** Nombre de réveils manqués à partir duquel l'appareil est déclaré hors ligne. */
        public readonly int $tolerance,
    ) {
    }

    public function isOffline(): bool
    {
        return $this->lastContact !== null && $this->missedWakes >= $this->tolerance;
    }

    /** Phrase prête pour un e-mail ou une réponse MCP (heure locale du serveur). */
    public function describe(): string
    {
        if ($this->lastContact === null) {
            return 'Aucun contact connu.';
        }
        $fmt = static fn (int $ts): string => date('d/m H:i', $ts);
        $period = $this->period >= 3600
            ? sprintf('%.1f h', $this->period / 3600)
            : sprintf('%d min', (int) round($this->period / 60));
        $text = sprintf('Dernier contact %s ; période de réveil retenue %s', $fmt($this->lastContact), $period);
        if ($this->observedPeriod !== null && $this->configuredPeriod !== null) {
            $text .= sprintf(' (observée %d min, configurée %d min)', (int) round($this->observedPeriod / 60), (int) round($this->configuredPeriod / 60));
        }
        if ($this->missedWakes > 0 && $this->firstMissedAt !== null) {
            $text .= sprintf(' ; %d réveil(s) manqué(s), le premier était attendu vers %s', $this->missedWakes, $fmt($this->firstMissedAt));
        }
        if ($this->nextExpectedAt !== null) {
            $text .= sprintf(' ; prochain réveil attendu vers %s', $fmt($this->nextExpectedAt));
        }

        return $text . '.';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $d = static fn (?int $ts): ?string => $ts === null ? null : date('Y-m-d H:i:s', $ts);

        return [
            'last_contact' => $d($this->lastContact),
            'last_burst_start' => $d($this->lastBurstStart),
            'bursts_observed' => $this->bursts,
            'observed_period_seconds' => $this->observedPeriod,
            'configured_period_seconds' => $this->configuredPeriod,
            'period_seconds' => $this->period,
            'margin_seconds' => $this->margin,
            'first_missed_at' => $d($this->firstMissedAt),
            'next_expected_at' => $d($this->nextExpectedAt),
            'missed_wakes' => $this->missedWakes,
            'tolerance' => $this->tolerance,
            'offline' => $this->isOffline(),
            'summary' => $this->describe(),
        ];
    }
}

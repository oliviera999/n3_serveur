<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\TableConfig;
use App\Repository\OutputMonitorRepository;

/**
 * Calcule, PAR FAMILLE d'appareils, le seuil (en secondes) au-delà duquel un module est
 * considéré hors ligne — DÉRIVÉ du temps de veille configuré en BDD (FreqWakeUp) plutôt
 * qu'un forfait fixe de 3600 s.
 *
 * Contexte : les modules dorment (deep/light sleep) pendant `FreqWakeUp` secondes, valeur
 * pilotée depuis la BDD de contrôle (table `*Outputs`). Le forfait « 1 h » figé de l'ancienne
 * supervision déclenchait de fausses alertes « hors ligne » dès que la veille approchait ou
 * dépassait l'heure — en particulier la nuit, où le firmware ffp5cs multiplie `FreqWakeUp`.
 *
 * Ce résolveur reconstitue la durée réelle côté SERVEUR, sans toucher au firmware :
 *  - FFP3 : `FreqWakeUp` (GPIO 116), allongé la nuit par un facteur reflété en BDD via des
 *    lignes server-only (126 multiplicateur, 127 début nuit, 128 fin nuit) — miroir des
 *    constantes firmware `NIGHT_SLEEP_MULTIPLIER` / fenêtre 19h–6h.
 *  - N3PP / MSP1 : `FreqWakeUp` (GPIO 107), sans variation nocturne.
 *
 * Formule : seuil = veille_effective × TOLERANCE_CYCLES + MARGE, borné [MIN, MAX].
 */
class OfflineThresholdResolver
{
    /** Nombre de cycles de veille manqués tolérés avant de déclarer un module hors ligne. */
    private const TOLERANCE_CYCLES = 2;
    /** Marge fixe ajoutée (latence réseau / jitter d'ordonnancement CRON), en secondes. */
    private const MARGIN_SECONDS = 60;
    private const MIN_SECONDS = 60;
    private const MAX_SECONDS = 86400;

    /** GPIO stockant FreqWakeUp (temps de veille, s) : FFP3 = 116 ; N3PP/MSP = 107. */
    private const FREQ_WAKEUP_GPIO_FFP3 = 116;
    private const FREQ_WAKEUP_GPIO_SENSOR = 107;

    /** Veille par défaut (s) si FreqWakeUp est absent / invalide en BDD. */
    private const DEFAULT_FREQ_WAKEUP_SECONDS = 600;

    /** Lignes server-only FFP3 reflétant le comportement nocturne du firmware. */
    private const NIGHT_MULTIPLIER_GPIO = 126;
    private const NIGHT_START_HOUR_GPIO = 127;
    private const NIGHT_END_HOUR_GPIO = 128;
    private const DEFAULT_NIGHT_MULTIPLIER = 3;   // miroir NIGHT_SLEEP_MULTIPLIER (ffp5cs)
    private const DEFAULT_NIGHT_START_HOUR = 19;  // miroir sleep_decision.h (isNightHour)
    private const DEFAULT_NIGHT_END_HOUR = 6;

    public function __construct(private OutputMonitorRepository $outputMonitorRepo)
    {
    }

    /**
     * Familles supervisées et leur source de veille (table outputs + GPIO FreqWakeUp),
     * résolues pour l'environnement courant. `night` = la famille allonge sa veille la nuit.
     *
     * @return array<int, array{family: string, table: string, freqGpio: int, night: bool}>
     */
    public function defaultFamilies(): array
    {
        return [
            [
                'family' => 'FFP3',
                'table' => TableConfig::getOutputsTable(),
                'freqGpio' => self::FREQ_WAKEUP_GPIO_FFP3,
                'night' => true,
            ],
            [
                'family' => 'N3PP',
                'table' => TableConfig::getN3ppOutputsTable(),
                'freqGpio' => self::FREQ_WAKEUP_GPIO_SENSOR,
                'night' => false,
            ],
            [
                'family' => 'MSP1',
                'table' => TableConfig::getMspOutputsTable(),
                'freqGpio' => self::FREQ_WAKEUP_GPIO_SENSOR,
                'night' => false,
            ],
        ];
    }

    /**
     * Seuil hors-ligne (s) pour la famille demandée. `$nowHour` (0-23) est injectable pour
     * les tests ; sinon l'heure locale courante est utilisée.
     */
    public function resolveForFamily(string $family, ?int $nowHour = null): int
    {
        foreach ($this->defaultFamilies() as $entry) {
            if ($entry['family'] === $family) {
                return $this->resolve($entry['table'], $entry['freqGpio'], $entry['night'], $nowHour);
            }
        }

        // Famille inconnue : forfait sûr dérivé de la veille par défaut.
        return $this->bound(self::DEFAULT_FREQ_WAKEUP_SECONDS * self::TOLERANCE_CYCLES + self::MARGIN_SECONDS);
    }

    private function resolve(string $table, int $freqGpio, bool $nightAware, ?int $nowHour): int
    {
        $sleep = $this->readPositiveInt($table, $freqGpio, self::DEFAULT_FREQ_WAKEUP_SECONDS);

        if ($nightAware) {
            $hour = $nowHour ?? (int) date('G');
            if ($this->isNight($hour, $table)) {
                $multiplier = $this->readPositiveInt($table, self::NIGHT_MULTIPLIER_GPIO, self::DEFAULT_NIGHT_MULTIPLIER);
                $sleep *= max(1, $multiplier);
            }
        }

        return $this->bound($sleep * self::TOLERANCE_CYCLES + self::MARGIN_SECONDS);
    }

    /**
     * Fenêtre de nuit à cheval possible sur minuit (défaut 19h→6h, comme le firmware).
     */
    private function isNight(int $hour, string $table): bool
    {
        $start = $this->clampHour($this->readPositiveInt($table, self::NIGHT_START_HOUR_GPIO, self::DEFAULT_NIGHT_START_HOUR, true));
        $end = $this->clampHour($this->readPositiveInt($table, self::NIGHT_END_HOUR_GPIO, self::DEFAULT_NIGHT_END_HOUR, true));

        if ($start === $end) {
            return false;
        }

        return $start < $end
            ? ($hour >= $start && $hour < $end)
            : ($hour >= $start || $hour < $end);
    }

    private function clampHour(int $hour): int
    {
        return max(0, min(23, $hour));
    }

    private function bound(int $seconds): int
    {
        return max(self::MIN_SECONDS, min(self::MAX_SECONDS, $seconds));
    }

    /**
     * Lit un entier positif depuis la BDD, avec repli sur `$default` si absent/vide/invalide.
     * `$allowZero` autorise la valeur 0 (utile pour une heure de fin de nuit à minuit).
     */
    private function readPositiveInt(string $table, int $gpio, int $default, bool $allowZero = false): int
    {
        try {
            $raw = $this->outputMonitorRepo->findStateByGpio($table, $gpio);
        } catch (\Throwable) {
            return $default;
        }

        if ($raw === null || $raw === '' || !is_numeric($raw)) {
            return $default;
        }

        $value = (int) $raw;
        if ($value < 0 || (!$allowZero && $value === 0)) {
            return $default;
        }

        return $value;
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Realtime;

/**
 * Mathématiques et assemblage communs de la santé/uptime des fournisseurs temps réel.
 *
 * Ce trait factorise UNIQUEMENT la mécanique repository-AGNOSTIQUE (aucun accès BDD ici) :
 *   - la formule de pourcentage d'uptime {@see self::uptimePercentage()} ;
 *   - le calcul d'uptime « capteurs » à intervalle régulier {@see self::sensorUptimePercentage()} ;
 *   - la durée de fonctionnement du module {@see self::moduleUptimeSecondsFromDate()} ;
 *   - l'assemblage du tableau getSystemHealth() {@see self::assembleSystemHealth()} et
 *     {@see self::emptySystemHealth()}.
 *
 * Les spécificités par famille (source des lectures, seuils/GPIO « online », device IP,
 * agrégation événementielle Pgl…) restent dans chaque fournisseur : elles fournissent les
 * valeurs déjà calculées à ces helpers. Les valeurs retournées sont volontairement identiques
 * aux implémentations d'origine (mêmes clés, ordre, types, arrondis).
 */
trait RealtimeHealthTrait
{
    /**
     * Latence moyenne estimée (s) exposée quand le module est en ligne.
     *
     * NB : méthode plutôt que constante de trait — les constantes de trait ne
     * sont supportées qu'à partir de PHP 8.2, or le projet cible PHP 8.1+.
     */
    protected function healthEstimatedLatencySeconds(): float
    {
        return 3.5;
    }

    /**
     * Formule commune de pourcentage d'uptime : min(actual / expected * 100, 100).
     * Garde-fou : renvoie 0.0 si le nombre attendu est nul (évite la division par zéro).
     */
    protected function uptimePercentage(float $actualCount, float $expectedCount): float
    {
        if ($expectedCount == 0.0) {
            return 0.0;
        }
        return min($actualCount / $expectedCount * 100, 100.0);
    }

    /**
     * Uptime « capteurs » : le nombre de lectures attendues sur la période vaut
     * (jours * 24 * 60) / intervalle_minutes. L'appelant fournit le nombre réel de lectures
     * (issu de son repository) sur la même fenêtre.
     */
    protected function sensorUptimePercentage(int $days, int $intervalMinutes, int $actualReadings): float
    {
        // Garde-fou (6.34.0) : un intervalle nul lèverait DivisionByZeroError — fatale en
        // PHP 8. Les appelants passent des constantes non nulles, mais le paramètre est un
        // `int` libre ; on répond 0 % comme le fait déjà uptimePercentage() sur un attendu nul.
        if ($intervalMinutes <= 0) {
            return 0.0;
        }

        $expected = ($days * 24 * 60) / $intervalMinutes;
        return $this->uptimePercentage((float) $actualReadings, (float) $expected);
    }

    /**
     * Durée (en secondes) depuis la première mesure enregistrée — sémantique base/FFP3 :
     * null si la date est absente, sinon (int)(maintenant - date). Retourne null si aucune date.
     */
    protected function moduleUptimeSecondsFromDate(?string $firstReadingDate): ?int
    {
        if ($firstReadingDate === null) {
            return null;
        }

        // Corrigé en 6.34.0 : strtotime() renvoie false sur une date illisible, et
        // `time() - false` valait `time() - 0` — soit une durée de fonctionnement
        // affichée de ~56 ans. Une date illisible est une absence d'information : null.
        $timestamp = strtotime($firstReadingDate);
        if ($timestamp === false) {
            return null;
        }

        return (int) (time() - $timestamp);
    }

    /**
     * Tableau getSystemHealth() « aucune mesure disponible » : tous les compteurs à zéro/null.
     * Seul le device IP dépend de la famille (fourni par l'appelant).
     *
     * @return array{
     *   online: bool,
     *   last_reading: string|null,
     *   last_reading_ts: int|null,
     *   last_reading_ago_seconds: int|null,
     *   uptime_percentage: float,
     *   readings_today: int,
     *   average_latency_seconds: float|null,
     *   device_ip: string|null,
     *   module_uptime_seconds: int|null
     * }
     */
    protected function emptySystemHealth(?string $deviceIp): array
    {
        return [
            'online' => false,
            'last_reading' => null,
            'last_reading_ts' => null,
            'last_reading_ago_seconds' => null,
            'uptime_percentage' => 0.0,
            'readings_today' => 0,
            'average_latency_seconds' => null,
            'device_ip' => $deviceIp,
            'module_uptime_seconds' => null,
        ];
    }

    /**
     * Assemble le tableau getSystemHealth() « mesure disponible » à partir des valeurs déjà
     * calculées par le fournisseur. La latence moyenne n'est exposée que si le module est en ligne.
     *
     * @return array{
     *   online: bool,
     *   last_reading: string|null,
     *   last_reading_ts: int|null,
     *   last_reading_ago_seconds: int|null,
     *   uptime_percentage: float,
     *   readings_today: int,
     *   average_latency_seconds: float|null,
     *   device_ip: string|null,
     *   module_uptime_seconds: int|null
     * }
     */
    protected function assembleSystemHealth(
        bool $isOnline,
        ?string $lastReading,
        ?int $lastReadingTs,
        ?int $lastReadingAgoSeconds,
        float $uptimePercentage,
        int $readingsToday,
        ?string $deviceIp,
        ?int $moduleUptimeSeconds,
    ): array {
        return [
            'online' => $isOnline,
            'last_reading' => $lastReading,
            'last_reading_ts' => $lastReadingTs,
            'last_reading_ago_seconds' => $lastReadingAgoSeconds,
            'uptime_percentage' => $uptimePercentage,
            'readings_today' => $readingsToday,
            'average_latency_seconds' => $isOnline ? $this->healthEstimatedLatencySeconds() : null,
            'device_ip' => $deviceIp,
            'module_uptime_seconds' => $moduleUptimeSeconds,
        ];
    }
}

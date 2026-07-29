<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\TableConfig;
use App\Notification\NotificationCategory;
use App\Notification\Severity;
use App\Repository\HeartbeatMonitorRepository;
use App\Service\Availability\AvailabilityIncident;
use App\Service\Availability\AvailabilityNotifier;

/**
 * Supervision « appareil silencieux » (heartbeat) GÉNÉRALISÉE À TOUTES LES FAMILLES.
 *
 * Historiquement, seul FFP3 alertait quand un appareil cessait d'émettre
 * (via SystemHealthService, sur la table de données). Ce service factorise la logique
 * en la rendant PARAMÉTRIQUE PAR FAMILLE : il interroge la dernière date de heartbeat de
 * chaque famille (FFP3 `ffp3Heartbeat`, N3PP `n3ppHeartbeat`, MSP1 `msp1Heartbeat` —
 * noms résolus via {@see TableConfig}) et, si le dernier battement dépasse le seuil
 * d'inactivité, route une alerte P1/Disponibilité via {@see NotificationService}.
 *
 * Anti-spam : chaque famille a son propre INCIDENT de disponibilité
 * (`heartbeat:offline:<family>`) suivi par {@see AvailabilityNotifier} — UN e-mail quand
 * l'appareil se tait, UN e-mail quand il réémet, RIEN entre les deux. Sans ce collaborateur
 * (construction directe hors container), le service retombe sur l'ancien comportement :
 * une alerte par passage, dé-dupliquée par le seul cooldown de l'AlertThrottler.
 *
 * Choix de robustesse : une table SANS aucun heartbeat (null) est IGNORÉE (l'appareil
 * n'a jamais émis = famille non déployée), afin de ne pas générer de fausses alertes à
 * chaque cycle CRON. On n'alerte que sur un appareil ayant un historique devenu obsolète.
 *
 * DEUX GARDE-FOUS contre les fausses alertes « silencieux » (correctif juillet 2026) :
 *
 *  1. **UN MODULE QUI ENVOIE DES DONNÉES N'EST PAS SILENCIEUX.** Le heartbeat et le POST
 *     de mesures sont deux flux INDÉPENDANTS et de cadences très différentes : côté
 *     ffp5cs le heartbeat part au plus une fois par cycle de réveil, en « fire and
 *     forget » (aucune file de reprise, `lastHeartbeat` avancé même en cas d'échec, envoi
 *     déclenché pendant la fenêtre de reconnexion WiFi), là où les mesures sont postées
 *     toutes les 30 s en éveil AVEC file de reprise et rejeu SD. Un seul heartbeat perdu
 *     suffisait donc à déclarer « silencieux » un module dont les données arrivaient
 *     parfaitement. On croise désormais les deux sources : tant que la dernière MESURE est
 *     fraîche (même seuil), aucune alerte n'est émise (cf. {@see $lastDataProviders}).
 *  2. **Le seuil configuré est un PLANCHER.** Le seuil dérivé de la veille
 *     ({@see OfflineThresholdResolver}) modélise la cadence des DONNÉES ; l'appliquer tel
 *     quel au heartbeat raccourcissait la tolérance historique (3600 s → ~1260 s en
 *     journée). Il ne peut plus que l'ALLONGER (facteur nuit), jamais la raccourcir.
 */
class DeviceHealthService
{
    /** Seuil d'inactivité par défaut (secondes) avant de considérer un appareil silencieux. */
    private const DEFAULT_OFFLINE_THRESHOLD_SECONDS = 3600;

    private int $offlineThresholdSeconds;

    /**
     * @param array<int, array{family: string, table: string}>|null $families
     *        Familles à superviser (défaut : FFP3, N3PP, MSP1 via TableConfig).
     *        Surchargeable pour les tests.
     * @param array<string, callable(): ?string>|null $lastDataProviders
     *        Par famille, une closure retournant la date SQL de la dernière MESURE reçue
     *        (table de données). Sert de contre-preuve : des mesures fraîches interdisent
     *        l'alerte « silencieux ». Absente → seul le heartbeat est consulté.
     * @param AvailabilityNotifier|null $availability
     *        Machine à états « un mail à la perte, un mail au retour ». Absente → ancien
     *        comportement (une alerte par passage, bornée par le seul cooldown anti-spam).
     */
    public function __construct(
        private HeartbeatMonitorRepository $heartbeatRepo,
        private NotificationService $notifier,
        private LogService $logger,
        ?int $offlineThresholdSeconds = null,
        private ?array $families = null,
        private ?OfflineThresholdResolver $thresholdResolver = null,
        private ?OperationalSettingsService $operationalSettings = null,
        private ?array $lastDataProviders = null,
        private ?AvailabilityNotifier $availability = null,
    ) {
        $this->offlineThresholdSeconds = $offlineThresholdSeconds
            ?? $this->operationalSettings?->int('HEARTBEAT_OFFLINE_THRESHOLD_SECONDS', self::DEFAULT_OFFLINE_THRESHOLD_SECONDS)
            ?? (int) ($_ENV['HEARTBEAT_OFFLINE_THRESHOLD_SECONDS'] ?? self::DEFAULT_OFFLINE_THRESHOLD_SECONDS);
        if ($this->offlineThresholdSeconds <= 0) {
            $this->offlineThresholdSeconds = self::DEFAULT_OFFLINE_THRESHOLD_SECONDS;
        }
    }

    /**
     * Familles supervisées par défaut, avec leur table heartbeat résolue par l'environnement.
     *
     * @return array<int, array{family: string, table: string}>
     */
    public static function defaultFamilies(): array
    {
        return [
            ['family' => 'FFP3', 'table' => TableConfig::getHeartbeatTable()],
            ['family' => 'N3PP', 'table' => TableConfig::getN3ppHeartbeatTable()],
            ['family' => 'MSP1', 'table' => TableConfig::getMspHeartbeatTable()],
        ];
    }

    /**
     * Vérifie chaque famille et alerte sur celles dont l'appareil est silencieux.
     *
     * @return int Nombre de familles DÉTECTÉES silencieuses (utile pour le log / les tests).
     *             Ce n'est pas un compteur d'e-mails : un incident déjà annoncé reste compté
     *             tout en restant muet (cf. {@see AvailabilityNotifier}).
     */
    public function checkAllFamilies(): int
    {
        $families = $this->families ?? self::defaultFamilies();
        $alerted = 0;

        foreach ($families as $entry) {
            if ($this->checkFamily($entry['family'], $entry['table'])) {
                $alerted++;
            }
        }

        return $alerted;
    }

    /**
     * Vérifie une famille donnée. Retourne vrai si une alerte a été émise.
     */
    public function checkFamily(string $family, string $table): bool
    {
        try {
            $lastSeen = $this->heartbeatRepo->getLastHeartbeatDate($table);
        } catch (\Throwable $e) {
            // Table indisponible / invalide : on log et on n'alerte pas (fail-safe).
            $this->logger->warning('DeviceHealthService: lecture heartbeat impossible', [
                'family' => $family,
                'table' => $table,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        if ($lastSeen === null) {
            // Aucun heartbeat : famille non déployée -> on ne génère pas de bruit.
            $this->logger->info('DeviceHealthService: aucun heartbeat (famille ignorée)', [
                'family' => $family,
            ]);

            return false;
        }

        $lastTs = strtotime($lastSeen);
        if ($lastTs === false) {
            $this->logger->error('DeviceHealthService: date heartbeat invalide', [
                'family' => $family,
                'value' => $lastSeen,
            ]);

            return false;
        }

        $threshold = $this->resolveThresholdSeconds($family);
        $ageSeconds = time() - $lastTs;
        if ($ageSeconds <= $threshold) {
            $this->logger->info('DeviceHealthService: appareil en ligne', [
                'family' => $family,
                'age_seconds' => $ageSeconds,
                'threshold_seconds' => $threshold,
            ]);
            $this->markOnline($family);

            return false;
        }

        // Contre-preuve : des mesures fraîches prouvent que le module émet, quelle que
        // soit la vétusté du heartbeat (flux distinct, sans reprise sur échec).
        $dataAge = $this->lastDataAgeSeconds($family);
        if ($dataAge !== null && $dataAge <= $threshold) {
            $this->logger->info('DeviceHealthService: heartbeat en retard mais données fraîches', [
                'family' => $family,
                'heartbeat_age_seconds' => $ageSeconds,
                'data_age_seconds' => $dataAge,
                'threshold_seconds' => $threshold,
            ]);
            $this->markOnline($family);

            return false;
        }

        return $this->alertOffline($family, $ageSeconds, $threshold);
    }

    /**
     * Seuil d'inactivité (s) pour la famille. Le forfait configuré
     * ({@see $offlineThresholdSeconds}) est un PLANCHER : le seuil dérivé du temps de
     * veille ({@see OfflineThresholdResolver}, facteur nuit FFP3) ne peut que l'allonger.
     * Il modélise la cadence des données, plus courte que celle du heartbeat : le prendre
     * tel quel produisait des alertes « silencieux » sur un module parfaitement vivant.
     */
    private function resolveThresholdSeconds(string $family): int
    {
        if ($this->thresholdResolver !== null) {
            return max($this->thresholdResolver->resolveForFamily($family), $this->offlineThresholdSeconds);
        }

        return $this->offlineThresholdSeconds;
    }

    /**
     * Âge (s) de la dernière mesure reçue pour la famille, ou null si aucune source de
     * données n'est câblée, si la table est vide ou si la lecture échoue (fail-safe : on
     * ne bloque jamais l'alerte sur une erreur de lecture).
     */
    private function lastDataAgeSeconds(string $family): ?int
    {
        $provider = $this->lastDataProviders[$family] ?? null;
        if (!is_callable($provider)) {
            return null;
        }

        try {
            $lastData = $provider();
        } catch (\Throwable $e) {
            $this->logger->warning('DeviceHealthService: lecture dernière mesure impossible', [
                'family' => $family,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (!is_string($lastData) || $lastData === '') {
            return null;
        }

        $ts = strtotime($lastData);

        return $ts === false ? null : time() - $ts;
    }

    /**
     * Referme l'incident de disponibilité d'une famille : émet l'e-mail « de nouveau en
     * ligne » si — et seulement si — une panne annoncée était en cours. No-op sans
     * machine à états (ancien comportement) ou si l'appareil n'avait jamais été signalé.
     */
    private function markOnline(string $family): void
    {
        $this->availability?->reportOnline(AvailabilityIncident::heartbeat($family));
    }

    /**
     * Signale la famille silencieuse. Avec la machine à états, l'e-mail P1 ne part qu'à la
     * BASCULE (une seule fois par panne) ; sans elle, on retombe sur l'envoi par passage
     * borné par le seul cooldown anti-spam.
     *
     * @return bool Toujours vrai : la famille EST détectée silencieuse, que l'e-mail parte
     *              (première fois) ou soit tu (incident déjà annoncé)
     */
    private function alertOffline(string $family, int $ageSeconds, int $thresholdSeconds): bool
    {
        $minutes = (int) round($ageSeconds / 60);
        $this->logger->critical('DeviceHealthService: appareil silencieux', [
            'family' => $family,
            'age_seconds' => $ageSeconds,
        ]);

        $message = sprintf(
            "Aucun battement de cœur (heartbeat) reçu de l'appareil %s depuis environ %d minute(s) "
            . "(seuil : %d minute(s)), et aucune mesure récente non plus.\nL'appareil ne transmet "
            . 'plus : vérifiez son alimentation, sa connexion réseau ou son firmware.',
            strtoupper($family),
            $minutes,
            (int) round($thresholdSeconds / 60)
        );

        if ($this->availability !== null) {
            $this->availability->reportOffline(
                AvailabilityIncident::heartbeat($family),
                $message . "\n\nCet e-mail ne sera pas répété : un second message vous préviendra "
                . "dès que l'appareil réémettra."
            );

            return true;
        }

        $this->notifier->sendAlert(
            Severity::Critical,
            NotificationCategory::Availability,
            $family,
            'Appareil silencieux (heartbeat)',
            $message,
            'heartbeat:offline:' . strtolower($family)
        );

        return true;
    }
}

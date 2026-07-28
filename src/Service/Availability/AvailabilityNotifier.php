<?php

declare(strict_types=1);

namespace App\Service\Availability;

use App\Notification\NotificationCategory;
use App\Notification\Severity;
use App\Service\LogService;
use App\Service\NotificationService;
use App\Util\JsonFileStore;

/**
 * Machine à états « disponibilité » : DEUX e-mails par panne, pas un de plus.
 *
 *  1. un e-mail à la BASCULE en ligne → hors ligne (ouverture d'incident) ;
 *  2. un e-mail à la BASCULE hors ligne → en ligne (clôture d'incident) ;
 *  3. RIEN entre les deux, quelle que soit la durée de la panne.
 *
 * Pourquoi : jusqu'ici, la supervision hors-ligne ne s'appuyait que sur le cooldown de
 * l'{@see \App\Notification\AlertThrottler} (900 s pour une P1). Un appareil resté muet
 * plusieurs jours — cas courant sur N3PP — produisait donc un e-mail identique à CHAQUE
 * passage horaire du CRON, sur chacune des supervisions (heartbeat + flux de données) et
 * pour chacune des familles. Le cooldown limite la FRÉQUENCE ; il ne sait pas qu'il s'agit
 * du même incident encore ouvert. Cette machine à états, elle, le sait : l'état par
 * incident (en ligne / hors ligne + horodatage de bascule) est persisté entre deux runs
 * CRON dans un simple fichier JSON ({@see JsonFileStore}, `var/cache/`).
 *
 * L'anti-spam historique est CONSERVÉ en seconde ligne (la clé d'incident est toujours
 * passée à {@see NotificationService}) : il alimente le journal `notification_log` et
 * amortit un éventuel battement (offline → online → offline en quelques minutes).
 *
 * Robustesse d'envoi : un e-mail non parti (SMTP KO, politique de notification muette)
 * ne consomme PAS la bascule — la tentative est rejouée au passage suivant, dans la
 * limite de {@see self::MAX_NOTICE_ATTEMPTS} essais, après quoi l'incident reste ouvert
 * mais silencieux (jamais de boucle d'envoi infinie). Symétriquement, une panne dont
 * l'ouverture n'a jamais été annoncée ne déclenche pas d'e-mail de clôture : on n'annonce
 * pas la fin d'un incident que personne n'a vu commencer.
 *
 * Perte du fichier d'état (purge de `var/cache/`, redéploiement) : au pire un incident
 * en cours est ré-annoncé une fois, puis le silence reprend — jamais de régression vers
 * l'ancien flot d'e-mails récurrents.
 */
class AvailabilityNotifier
{
    private const STATUS_ONLINE = 'online';
    private const STATUS_OFFLINE = 'offline';

    /** Tentatives d'envoi maximales pour l'e-mail d'une bascule (anti-boucle). */
    private const MAX_NOTICE_ATTEMPTS = 3;

    public function __construct(
        private NotificationService $notifier,
        private LogService $logger,
        private JsonFileStore $store,
    ) {
    }

    /**
     * Signale l'appareil HORS LIGNE pour cet incident.
     *
     * Envoie l'e-mail d'ouverture au premier constat seulement : tant que l'incident
     * reste ouvert, les constats suivants sont silencieux.
     *
     * @param string   $message  Corps de l'e-mail d'ouverture (contexte, seuils, durée…)
     * @param Severity $severity Sévérité de l'e-mail d'ouverture (P1 par défaut)
     *
     * @return bool Vrai si un e-mail vient d'être émis
     */
    public function reportOffline(
        AvailabilityIncident $incident,
        string $message,
        Severity $severity = Severity::Critical
    ): bool {
        $state = $this->store->load();
        $record = $this->recordFor($state, $incident->key);
        $now = time();

        if ($record['status'] !== self::STATUS_OFFLINE) {
            // Bascule en ligne -> hors ligne : nouvel incident, e-mail d'ouverture à émettre.
            $record = $this->newRecord(self::STATUS_OFFLINE, $now, false);
        }

        $sent = false;
        if ($record['notified']) {
            $this->logger->info('AvailabilityNotifier: incident déjà annoncé, e-mail supprimé', [
                'incident' => $incident->key,
                'depuis' => $this->formatDuration(max(0, $now - $record['since'])),
            ]);
        } elseif ($record['attempts'] >= self::MAX_NOTICE_ATTEMPTS) {
            $this->logger->warning('AvailabilityNotifier: annonce hors-ligne abandonnée (tentatives épuisées)', [
                'incident' => $incident->key,
                'tentatives' => $record['attempts'],
            ]);
        } else {
            $record['attempts']++;
            $sent = $this->notifier->sendImmediateAlert(
                $severity,
                NotificationCategory::Availability,
                $incident->family,
                $incident->offlineSubject,
                $message,
                $incident->key
            );
            $record['notified'] = $sent;
        }

        $this->persist($state, $incident->key, $record);

        return $sent;
    }

    /**
     * Signale l'appareil EN LIGNE pour cet incident.
     *
     * Émet l'e-mail de clôture si — et seulement si — un incident annoncé était ouvert.
     * Sur un appareil déjà en ligne, l'appel est un no-op (aucun e-mail « tout va bien »).
     *
     * @return bool Vrai si un e-mail de rétablissement vient d'être émis
     */
    public function reportOnline(AvailabilityIncident $incident): bool
    {
        $state = $this->store->load();
        $record = $this->recordFor($state, $incident->key);
        $now = time();

        if ($record['status'] === self::STATUS_OFFLINE) {
            $outage = max(0, $now - $record['since']);
            // Incident jamais annoncé (politique muette, SMTP KO) : ne pas annoncer sa fin.
            $announced = $record['notified'];
            $record = $this->newRecord(self::STATUS_ONLINE, $now, !$announced);
            $record['outage'] = $outage;

            $this->logger->info('AvailabilityNotifier: appareil rétabli', [
                'incident' => $incident->key,
                'duree' => $this->formatDuration($outage),
                'incident_annonce' => $announced,
            ]);
        }

        $sent = false;
        if (!$record['notified'] && $record['attempts'] < self::MAX_NOTICE_ATTEMPTS) {
            $record['attempts']++;
            $sent = $this->notifier->sendImmediateAlert(
                Severity::Alert,
                NotificationCategory::Availability,
                $incident->family,
                $incident->recoverySubject,
                $this->recoveryMessage($incident, $record['outage']),
                $incident->key . ':recovery'
            );
            $record['notified'] = $sent;
        }

        $this->persist($state, $incident->key, $record);

        return $sent;
    }

    /** Indique si l'incident est actuellement OUVERT (appareil déclaré hors ligne). */
    public function isOffline(AvailabilityIncident $incident): bool
    {
        return $this->recordFor($this->store->load(), $incident->key)['status'] === self::STATUS_OFFLINE;
    }

    /** Corps de l'e-mail de clôture d'incident. */
    private function recoveryMessage(AvailabilityIncident $incident, int $outageSeconds): string
    {
        return sprintf(
            "L'appareil %s émet de nouveau : la supervision le considère en ligne.\n"
            . "L'interruption a duré environ %s.\n"
            . "Aucune action n'est requise — ce message clôt l'alerte « %s ».",
            $incident->family,
            $this->formatDuration($outageSeconds),
            $incident->offlineSubject
        );
    }

    /** Durée lisible en français : « 3 j 4 h », « 2 h 15 min », « 45 min ». */
    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . ' s';
        }

        $minutes = intdiv($seconds, 60);
        if ($minutes < 60) {
            return $minutes . ' min';
        }

        $hours = intdiv($minutes, 60);
        if ($hours < 24) {
            $rest = $minutes % 60;

            return $rest === 0 ? $hours . ' h' : sprintf('%d h %02d min', $hours, $rest);
        }

        $days = intdiv($hours, 24);
        $rest = $hours % 24;

        return $rest === 0 ? $days . ' j' : sprintf('%d j %d h', $days, $rest);
    }

    /**
     * État persisté d'un incident, normalisé (fichier absent/corrompu → appareil réputé
     * en ligne et déjà « annoncé » : aucune bascule, donc aucun e-mail intempestif).
     *
     * @param array<string, mixed> $state
     *
     * @return array{status: string, since: int, notified: bool, attempts: int, outage: int}
     */
    private function recordFor(array $state, string $key): array
    {
        $raw = $state[$key] ?? null;
        if (!is_array($raw)) {
            return $this->newRecord(self::STATUS_ONLINE, time(), true);
        }

        $status = isset($raw['status']) && $raw['status'] === self::STATUS_OFFLINE
            ? self::STATUS_OFFLINE
            : self::STATUS_ONLINE;

        return [
            'status' => $status,
            'since' => isset($raw['since']) && is_numeric($raw['since']) ? (int) $raw['since'] : time(),
            'notified' => (bool) ($raw['notified'] ?? true),
            'attempts' => isset($raw['attempts']) && is_numeric($raw['attempts']) ? (int) $raw['attempts'] : 0,
            'outage' => isset($raw['outage']) && is_numeric($raw['outage']) ? (int) $raw['outage'] : 0,
        ];
    }

    /**
     * @return array{status: string, since: int, notified: bool, attempts: int, outage: int}
     */
    private function newRecord(string $status, int $since, bool $notified): array
    {
        return [
            'status' => $status,
            'since' => $since,
            'notified' => $notified,
            'attempts' => 0,
            'outage' => 0,
        ];
    }

    /**
     * @param array<string, mixed>                                                        $state
     * @param array{status: string, since: int, notified: bool, attempts: int, outage: int} $record
     */
    private function persist(array $state, string $key, array $record): void
    {
        $state[$key] = $record;
        $this->store->save($state);
    }
}

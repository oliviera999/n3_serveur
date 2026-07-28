<?php

declare(strict_types=1);

namespace App\Service\Availability;

/**
 * Décrit un « incident de disponibilité » supervisé par {@see AvailabilityNotifier} :
 * sa clé de suivi (état persisté + anti-spam + journal `notification_log`), la famille
 * d'appareils concernée et les objets des deux seuls e-mails de son cycle de vie —
 * l'ouverture (appareil perdu) et la clôture (appareil retrouvé).
 *
 * Les fabriques statiques centralisent la CONVENTION DE CLÉ, réutilisée telle quelle
 * depuis l'ancien anti-spam pour ne pas casser la continuité de l'historique :
 *  - {@see self::heartbeat()} → `heartbeat:offline:<famille>` (lien avec l'appareil,
 *    supervisé par {@see \App\Service\DeviceHealthService}) ;
 *  - {@see self::dataFlow()} → `<famille>:offline` (flux de mesures, supervisé par
 *    {@see \App\Service\SystemHealthService}).
 *
 * Deux incidents distincts pour deux signaux distincts : un module peut battre du
 * cœur sans plus poster de mesures (capteur HS, POST en échec) — et inversement.
 * L'incident « flux de données » est toutefois SUBORDONNÉ à l'incident « appareil » :
 * tant que l'appareil est déclaré silencieux, l'alerte données est superflue et reste
 * muette (cf. {@see AvailabilityNotifier::isOffline()}), d'où UN SEUL e-mail par panne.
 */
final class AvailabilityIncident
{
    public function __construct(
        public readonly string $key,
        public readonly string $family,
        public readonly string $offlineSubject,
        public readonly string $recoverySubject,
    ) {
    }

    /** Incident « appareil silencieux » (heartbeat + mesures) d'une famille. */
    public static function heartbeat(string $family): self
    {
        return new self(
            'heartbeat:offline:' . strtolower($family),
            strtoupper($family),
            'Appareil silencieux (heartbeat)',
            'Appareil de nouveau en ligne'
        );
    }

    /** Incident « plus aucune mesure reçue » (table de données) d'une famille. */
    public static function dataFlow(string $family): self
    {
        return new self(
            strtolower($family) . ':offline',
            strtoupper($family),
            'Système hors ligne',
            'Réception des données rétablie'
        );
    }
}

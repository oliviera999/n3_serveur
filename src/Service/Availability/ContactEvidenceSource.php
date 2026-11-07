<?php

declare(strict_types=1);

namespace App\Service\Availability;

/**
 * Source des PREUVES de contact d'une famille d'appareils, consommée par la supervision de
 * disponibilité ({@see \App\Service\DeviceHealthService}, {@see \App\Service\SystemHealthService})
 * et par le modèle de réveil attendu ({@see WakeScheduleModel}).
 *
 * Un « contact » est une requête du module effectivement reçue par le serveur : POST de mesures
 * enregistré, heartbeat enregistré. Les horodatages sont des epoch Unix (heure serveur).
 */
interface ContactEvidenceSource
{
    /**
     * Horodatages des contacts récents (POST + heartbeats), ordre quelconque, fenêtre bornée
     * par l'implémentation (quelques dizaines de rafales suffisent au modèle).
     *
     * @return list<int>
     */
    public function recentContacts(string $family): array;

    /**
     * Dernier contact AUTHENTIFIÉ (HMAC ou clé API acceptés), ou null si inconnu. C'est la
     * seule preuve admise pour clore un incident « hors ligne ».
     */
    public function lastAuthenticatedContact(string $family): ?int;

    /**
     * Période de réveil configurée (FreqWakeUp effectif, facteur nuit compris), en secondes,
     * ou null si elle n'est pas lisible.
     */
    public function expectedPeriodSeconds(string $family): ?int;
}

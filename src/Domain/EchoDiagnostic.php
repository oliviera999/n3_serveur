<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Diagnostic d'écho d'un capteur à ultrasons, tel que posté par ffp5cs ≥ 15.33.
 *
 * Contrat (n3_firmwires ffp5cs/include/echo_diag.h) : `wlAquaDiag=<etat>,<timeouts>,<rejets>,<mm>`
 *  - etat     : ok | no_echo | out_of_range | disabled
 *  - timeouts : tirs sans aucun écho dans le cycle de lecture
 *  - rejets   : échos reçus mais hors plage valide [20 mm ; 4000 mm[
 *  - mm       : distance du dernier écho rejeté (0 = aucun)
 *
 * Permet enfin de distinguer à distance « aucun écho » (câble, alimentation, capteur mort) de
 * « écho hors plage » (objet ou eau collés au capteur) — incident du 30/10/2026,
 * docs/DIAGNOSTIC_FFP3_NIVEAU_AQUARIUM_2026-11.md. Valeur absente ou malformée → null (ignorée).
 */
final class EchoDiagnostic
{
    public const STATUSES = ['ok', 'no_echo', 'out_of_range', 'disabled'];

    public function __construct(
        public readonly string $status,
        public readonly int $timeouts,
        public readonly int $rejected,
        public readonly int $lastRejectedMm,
    ) {
    }

    public static function parse(?string $raw): ?self
    {
        if ($raw === null) {
            return null;
        }
        $parts = explode(',', trim($raw));
        if (count($parts) !== 4 || !in_array($parts[0], self::STATUSES, true)) {
            return null;
        }
        foreach ([1, 2, 3] as $i) {
            if (preg_match('/^\d{1,5}$/', $parts[$i]) !== 1) {
                return null;
            }
        }

        return new self(
            $parts[0],
            min(255, (int) $parts[1]),
            min(255, (int) $parts[2]),
            min(65535, (int) $parts[3]),
        );
    }

    /**
     * Interprétation lisible d'un statut stocké (colonne wl*Status), pour les alertes et le MCP.
     */
    public static function explain(?string $status, ?int $timeouts = null, ?int $rejected = null, ?int $lastRejectedMm = null): ?string
    {
        return match ($status) {
            'ok' => 'écho valide reçu',
            'no_echo' => sprintf(
                'aucun écho (%s tir(s) sans réponse) : câble/connecteur, alimentation 5 V ou capteur hors service',
                $timeouts !== null ? (string) $timeouts : '?'
            ),
            'out_of_range' => sprintf(
                'écho hors plage (%s rejet(s)%s) : objet, eau ou condensation au contact du capteur',
                $rejected !== null ? (string) $rejected : '?',
                $lastRejectedMm !== null && $lastRejectedMm > 0 ? ', dernier à ' . $lastRejectedMm . ' mm' : ''
            ),
            'disabled' => 'capteur désactivé par le firmware après des échecs consécutifs (test de réactivation périodique)',
            default => null,
        };
    }
}

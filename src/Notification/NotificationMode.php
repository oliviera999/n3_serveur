<?php

declare(strict_types=1);

namespace App\Notification;

/**
 * Mode de verbosité des notifications, configurable (variable d'env NOTIF_MODE).
 *
 * Agit comme un seuil sur la sévérité : seules les notifications dont la sévérité
 * est au moins aussi importante que le plancher du mode sont envoyées.
 *
 *  - none      : aucun envoi (maintenance, absence prolongée) ;
 *  - important : P1 + P2 (critique + alerte) — défaut recommandé ;
 *  - partial   : P1 + P2 + P3 (ajoute les infos) ;
 *  - full      : tout, y compris P4 diagnostic.
 */
enum NotificationMode: string
{
    case None = 'none';
    case Important = 'important';
    case Partial = 'partial';
    case Full = 'full';

    /** Construit le mode depuis une valeur libre (insensible à la casse), repli sur $default. */
    public static function fromString(?string $value, self $default = self::Full): self
    {
        if ($value === null) {
            return $default;
        }

        return self::tryFrom(strtolower(trim($value))) ?? $default;
    }

    /** Priorité maximale (incluse) laissée passer par ce mode. 0 = rien ne passe. */
    public function maxPriority(): int
    {
        return match ($this) {
            self::None => 0,
            self::Important => 2, // P1, P2
            self::Partial => 3,   // P1, P2, P3
            self::Full => 4,      // P1..P4
        };
    }

    /** Indique si une sévérité donnée est autorisée par ce mode. */
    public function allows(Severity $severity): bool
    {
        return $severity->priority() <= $this->maxPriority();
    }

    /** Libellé humain (français). */
    public function label(): string
    {
        return match ($this) {
            self::None => 'Aucun',
            self::Important => 'Important',
            self::Partial => 'Partiel',
            self::Full => 'Total',
        };
    }
}

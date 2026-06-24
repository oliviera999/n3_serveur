<?php

declare(strict_types=1);

namespace App\Notification;

/**
 * Catégorie (domaine fonctionnel) d'une notification.
 *
 * Axe orthogonal à la sévérité : permet de couper finement un type de mail
 * (via NOTIF_DISABLED_CATEGORIES) et, à terme, de router les alertes vers des
 * destinataires ou canaux différents.
 */
enum NotificationCategory: string
{
    /** Pompes, niveaux d'eau, marées. */
    case Hydraulic = 'hydraulic';
    /** Batterie, alimentation. */
    case Energy = 'energy';
    /** Température, humidité du sol. */
    case Environment = 'environment';
    /** Nourrissage. */
    case Feeding = 'feeding';
    /** Hors-ligne, heartbeat, absence de données. */
    case Availability = 'availability';
    /** Boot, veille/réveil, OTA. */
    case Lifecycle = 'lifecycle';
    /** Capture / upload photo. */
    case Camera = 'camera';
    /** Erreurs techniques serveur. */
    case System = 'system';

    /** Construit la catégorie depuis une valeur libre, ou null si inconnue/absente. */
    public static function fromString(?string $value): ?self
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return self::tryFrom(strtolower(trim($value)));
    }

    /** Libellé humain (français). */
    public function label(): string
    {
        return match ($this) {
            self::Hydraulic => 'Hydraulique',
            self::Energy => 'Énergie',
            self::Environment => 'Environnement',
            self::Feeding => 'Nourrissage',
            self::Availability => 'Disponibilité',
            self::Lifecycle => 'Cycle de vie',
            self::Camera => 'Caméra',
            self::System => 'Système',
        };
    }

    /** Pictogramme associé. */
    public function emoji(): string
    {
        return match ($this) {
            self::Hydraulic => '💧',
            self::Energy => '🔋',
            self::Environment => '🌡️',
            self::Feeding => '🐟',
            self::Availability => '📡',
            self::Lifecycle => '🔄',
            self::Camera => '📷',
            self::System => '🛠️',
        };
    }
}

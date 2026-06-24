<?php

declare(strict_types=1);

namespace App\Notification;

/**
 * Niveau de sévérité d'une notification (P1 → P4).
 *
 * Axe « importance » du système de notifications : croisé avec le NotificationMode
 * configuré, il détermine si une alerte doit partir et avec quel cooldown anti-spam
 * par défaut. Le code (P1..P4) est exposé dans le sujet des e-mails pour permettre
 * un filtrage/tri côté boîte de réception.
 */
enum Severity: string
{
    /** P1 — risque imminent vivant/matériel, action immédiate. */
    case Critical = 'critical';
    /** P2 — anomalie réelle à traiter sous quelques heures. */
    case Alert = 'alert';
    /** P3 — confirmation d'action normale / traçabilité. */
    case Info = 'info';
    /** P4 — rapports périodiques, démarrage, tests. */
    case Diagnostic = 'diagnostic';

    /** Code court hiérarchique (P1 = plus critique), utilisé dans les sujets. */
    public function code(): string
    {
        return match ($this) {
            self::Critical => 'P1',
            self::Alert => 'P2',
            self::Info => 'P3',
            self::Diagnostic => 'P4',
        };
    }

    /** Priorité numérique : 1 = plus critique, 4 = moins critique. */
    public function priority(): int
    {
        return match ($this) {
            self::Critical => 1,
            self::Alert => 2,
            self::Info => 3,
            self::Diagnostic => 4,
        };
    }

    /** Libellé humain (français). */
    public function label(): string
    {
        return match ($this) {
            self::Critical => 'Critique',
            self::Alert => 'Alerte',
            self::Info => 'Info',
            self::Diagnostic => 'Diagnostic',
        };
    }

    /** Pictogramme associé (sujets / templates). */
    public function emoji(): string
    {
        return match ($this) {
            self::Critical => '🔴',
            self::Alert => '🟠',
            self::Info => '🟡',
            self::Diagnostic => '⚪',
        };
    }

    /**
     * Cooldown anti-spam par défaut (secondes) entre deux envois de la même alerte.
     * Plus l'alerte est critique, plus le rappel peut être fréquent.
     */
    public function defaultCooldownSeconds(): int
    {
        return match ($this) {
            self::Critical => 900,         // 15 min
            self::Alert => 3600,           // 1 h
            self::Info => 6 * 3600,        // 6 h
            self::Diagnostic => 24 * 3600, // 24 h
        };
    }
}

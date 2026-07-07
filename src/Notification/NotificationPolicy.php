<?php

declare(strict_types=1);

namespace App\Notification;

use App\Service\OperationalSettingsService;

/**
 * Politique de notification : décide si une alerte donnée doit être envoyée.
 *
 * Combine deux réglages configurables :
 *  - un MODE global (NotificationMode) qui plafonne la sévérité ;
 *  - une liste de CATÉGORIES désactivées (coupe-circuit par domaine).
 *
 * Construite depuis l'environnement par fromEnv() :
 *  - NOTIF_MODE = none | important | partial | full        (défaut : full)
 *  - NOTIF_DISABLED_CATEGORIES = liste de NotificationCategory séparée par des virgules
 */
final class NotificationPolicy
{
    /** @var array<string, true> Catégories désactivées, indexées par valeur. */
    private array $disabled = [];

    /**
     * @param NotificationCategory[] $disabledCategories
     */
    public function __construct(
        public readonly NotificationMode $mode,
        array $disabledCategories = []
    ) {
        foreach ($disabledCategories as $category) {
            $this->disabled[$category->value] = true;
        }
    }

    /** Construit la politique depuis les variables d'environnement. */
    public static function fromEnv(): self
    {
        $mode = NotificationMode::fromString($_ENV['NOTIF_MODE'] ?? null, NotificationMode::Full);

        $disabled = [];
        $raw = $_ENV['NOTIF_DISABLED_CATEGORIES'] ?? '';
        if (is_string($raw) && trim($raw) !== '') {
            foreach (explode(',', $raw) as $token) {
                $category = NotificationCategory::fromString($token);
                if ($category !== null) {
                    $disabled[] = $category;
                }
            }
        }

        return new self($mode, $disabled);
    }

    /** Construit la politique depuis les réglages opérationnels (BDD > .env). */
    public static function fromOperationalSettings(OperationalSettingsService $ops): self
    {
        $mode = NotificationMode::fromString(
            $ops->string('NOTIF_MODE', 'full'),
            NotificationMode::Full
        );

        $disabled = [];
        $raw = $ops->string('NOTIF_DISABLED_CATEGORIES', '');
        if (trim($raw) !== '') {
            foreach (explode(',', $raw) as $token) {
                $category = NotificationCategory::fromString($token);
                if ($category !== null) {
                    $disabled[] = $category;
                }
            }
        }

        return new self($mode, $disabled);
    }

    /** Détermine si une notification (sévérité + catégorie optionnelle) doit partir. */
    public function shouldSend(Severity $severity, ?NotificationCategory $category = null): bool
    {
        if (!$this->mode->allows($severity)) {
            return false;
        }

        if ($category !== null && isset($this->disabled[$category->value])) {
            return false;
        }

        return true;
    }

    /** Indique si une catégorie est explicitement désactivée. */
    public function isCategoryDisabled(NotificationCategory $category): bool
    {
        return isset($this->disabled[$category->value]);
    }
}

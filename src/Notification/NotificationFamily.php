<?php

declare(strict_types=1);

namespace App\Notification;

/**
 * Familles d'appareils pour la politique de notifications serveur.
 */
enum NotificationFamily: string
{
    case Ffp3 = 'FFP3';
    case Msp1 = 'MSP1';
    case N3pp = 'N3PP';
    case GalleryFfp3 = 'GALLERY_FFP3';
    case GalleryMsp1 = 'GALLERY_MSP1';
    case GalleryN3pp = 'GALLERY_N3PP';

    /** Clé interne pour le mapping GPIO / stockage. */
    public function storageKey(): string
    {
        return match ($this) {
            self::Ffp3 => 'ffp3',
            self::Msp1 => 'msp1',
            self::N3pp => 'n3pp',
            self::GalleryFfp3 => 'gallery_ffp3',
            self::GalleryMsp1 => 'gallery_msp1',
            self::GalleryN3pp => 'gallery_n3pp',
        };
    }

    /**
     * Construit la famille depuis la chaîne passée aux alertes (ex. sendAlert).
     */
    public static function fromAlertFamily(?string $family): ?self
    {
        if ($family === null || trim($family) === '') {
            return null;
        }

        $normalized = strtoupper(trim($family));

        return match ($normalized) {
            'FFP3' => self::Ffp3,
            'MSP1' => self::Msp1,
            'N3PP' => self::N3pp,
            'GALLERY_FFP3', 'GALLERY-FFP3' => self::GalleryFfp3,
            'GALLERY_MSP1', 'GALLERY-MSP1' => self::GalleryMsp1,
            'GALLERY_N3PP', 'GALLERY-N3PP' => self::GalleryN3pp,
            default => self::tryFrom($normalized),
        };
    }

    public static function fromGallerySlug(string $slug): ?self
    {
        return match (strtolower(trim($slug))) {
            'ffp3' => self::GalleryFfp3,
            'msp1' => self::GalleryMsp1,
            'n3pp' => self::GalleryN3pp,
            default => null,
        };
    }

    /** @return list<NotificationCategory> */
    public static function allCategories(): array
    {
        return NotificationCategory::cases();
    }
}

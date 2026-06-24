<?php

declare(strict_types=1);

namespace App\Config;

use App\Notification\NotificationFamily;

/**
 * GPIO server-only pour la politique de notifications par famille d'appareil.
 *
 * Ces GPIO ne sont pas synchronisés vers le firmware (exclus des GET outputs).
 * Le GPIO mailNotif reste le booléen firmware (0/1 ou checked/false).
 */
final class NotificationPolicyGpioMap
{
    /** @var array<string, array{notifMode: int, notifCategories: int, mailNotif: int}> */
    private const FAMILY_GPIO = [
        'ffp3' => ['notifMode' => 124, 'notifCategories' => 125, 'mailNotif' => 101],
        'msp1' => ['notifMode' => 108, 'notifCategories' => 109, 'mailNotif' => 101],
        'n3pp' => ['notifMode' => 108, 'notifCategories' => 109, 'mailNotif' => 101],
        'gallery_ffp3' => ['notifMode' => 107, 'notifCategories' => 108, 'mailNotif' => 103],
        'gallery_msp1' => ['notifMode' => 107, 'notifCategories' => 108, 'mailNotif' => 103],
        'gallery_n3pp' => ['notifMode' => 107, 'notifCategories' => 108, 'mailNotif' => 103],
    ];

    /**
     * @return array{notifMode: int, notifCategories: int, mailNotif: int}|null
     */
    public static function gpiosForFamily(NotificationFamily $family): ?array
    {
        return self::FAMILY_GPIO[$family->storageKey()] ?? null;
    }

    /**
     * GPIO exclus des réponses firmware pour une famille.
     *
     * @return int[]
     */
    public static function serverOnlyGpios(NotificationFamily $family): array
    {
        $gpios = self::gpiosForFamily($family);
        if ($gpios === null) {
            return [];
        }

        return [$gpios['notifMode'], $gpios['notifCategories']];
    }

    /**
     * @return int[]
     */
    public static function serverOnlyGpiosForMspN3pp(): array
    {
        return [108, 109];
    }

    /**
     * @return int[]
     */
    public static function serverOnlyGpiosForGallery(): array
    {
        return [107, 108];
    }
}

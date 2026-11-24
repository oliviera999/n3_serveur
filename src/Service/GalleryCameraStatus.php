<?php

declare(strict_types=1);

namespace App\Service;

use App\Util\DisplayTime;
use DateTimeImmutable;

/**
 * Statut réel d'une caméra de galerie (ESP32-CAM uploadphotosserver), affiché par le timelapse
 * à la place du « EN LIGNE » fixe d'avant 6.65.0.
 *
 * Deux signaux, tous deux déjà enregistrés par le serveur :
 *  - la dernière photo reçue (horodatage du fichier de la galerie) ;
 *  - le dernier contact de la carte (`Boards.last_request`, mis à jour à chaque lecture de
 *    l'état des sorties par le firmware, même sans photo).
 *
 * - `online`    : photo reçue depuis moins de PHOTO_FRESH_SECONDS ;
 * - `no_photo`  : la carte contacte le serveur (< CONTACT_FRESH_SECONDS) mais n'envoie plus de
 *                 photo (normal la nuit si la caméra ne photographie pas, sinon à vérifier) ;
 * - `offline`   : ni photo ni contact récents ;
 * - `unknown`   : aucune photo ni contact connus.
 */
final class GalleryCameraStatus
{
    public const PHOTO_FRESH_SECONDS = 2 * 3600;

    public const CONTACT_FRESH_SECONDS = 30 * 60;

    /**
     * @param int|null    $lastPhotoTs  horodatage Unix de la dernière photo
     * @param string|null $lastContact  `Boards.last_request` (heure serveur, 'Y-m-d H:i:s')
     *
     * @return array{state: string, label: string, detail: string, last_photo: string, last_contact: string}
     */
    public static function evaluate(?int $lastPhotoTs, ?string $lastContact, int $now): array
    {
        $contactTs = null;
        if ($lastContact !== null && trim($lastContact) !== '') {
            try {
                $contactTs = (new DateTimeImmutable($lastContact, new \DateTimeZone($_ENV['APP_TIMEZONE'] ?? 'Europe/Paris')))->getTimestamp();
            } catch (\Exception) {
                $contactTs = null;
            }
        }

        $photoStr = $lastPhotoTs !== null ? self::fmt($lastPhotoTs) : '';
        $contactStr = $contactTs !== null ? self::fmt($contactTs) : '';

        if ($lastPhotoTs !== null && $now - $lastPhotoTs <= self::PHOTO_FRESH_SECONDS) {
            return [
                'state' => 'online',
                'label' => 'EN LIGNE',
                'detail' => 'dernière photo ' . self::ago($now - $lastPhotoTs),
                'last_photo' => $photoStr,
                'last_contact' => $contactStr,
            ];
        }

        if ($contactTs !== null && $now - $contactTs <= self::CONTACT_FRESH_SECONDS) {
            return [
                'state' => 'no_photo',
                'label' => 'CONNECTÉE, SANS PHOTO',
                'detail' => $photoStr !== '' ? 'aucune photo depuis le ' . $photoStr : 'aucune photo reçue',
                'last_photo' => $photoStr,
                'last_contact' => $contactStr,
            ];
        }

        if ($lastPhotoTs === null && $contactTs === null) {
            return ['state' => 'unknown', 'label' => 'STATUT INCONNU', 'detail' => 'aucune photo ni contact enregistré', 'last_photo' => '', 'last_contact' => ''];
        }

        $last = max((int) $lastPhotoTs, (int) $contactTs);

        return [
            'state' => 'offline',
            'label' => 'HORS LIGNE',
            'detail' => 'dernier contact le ' . self::fmt($last),
            'last_photo' => $photoStr,
            'last_contact' => $contactStr,
        ];
    }

    /** Durée lisible : « il y a 5 min », « il y a 1 h 20 ». */
    private static function ago(int $seconds): string
    {
        $seconds = max(0, $seconds);
        if ($seconds < 60) {
            return "à l'instant";
        }
        $min = intdiv($seconds, 60);
        if ($min < 60) {
            return 'il y a ' . $min . ' min';
        }
        $h = intdiv($min, 60);
        $m = $min % 60;

        return 'il y a ' . $h . ' h' . ($m > 0 ? ' ' . str_pad((string) $m, 2, '0', STR_PAD_LEFT) : '');
    }

    /** Date en heure de Casablanca (heure locale du projet). */
    private static function fmt(int $ts): string
    {
        return DisplayTime::toDisplay((new DateTimeImmutable())->setTimestamp($ts), 'd/m/Y à H:i');
    }
}

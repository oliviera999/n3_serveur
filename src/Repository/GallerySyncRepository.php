<?php

declare(strict_types=1);

namespace App\Repository;

use InvalidArgumentException;

/**
 * Sessions de synchronisation des galeries photo (uploadphotosserver).
 *
 * Le firmware ESP32-CAM utilise la carte SD comme file d'attente hors-ligne.
 * Quand le WiFi revient, il ouvre une session ({@see startSession}) en annoncant
 * le nombre de photos en attente, pousse les photos manquantes (chaque upload
 * incremente {@see incrementReceived} via l'en-tete X-Sync-Session) puis cloture
 * la session ({@see finishSession}).
 *
 * Alimente l'indicateur de connexion + la jauge de progression ({@see getStatus})
 * et le mail recapitulatif de fin de transfert.
 *
 * Table unique non prefixee par environnement (comme les tables UploadPhotoNOutputs) :
 * le slug discrimine la galerie. Whiteliste stricte des slugs pour eviter toute injection.
 */
class GallerySyncRepository extends AbstractRepository
{
    private const TABLE = 'gallerySyncSessions';

    /** @var list<string> */
    private const ALLOWED_SLUGS = ['msp1', 'n3pp', 'ffp3'];

    /** Fenetre (secondes) au-dela de laquelle l'appareil est considere hors-ligne. */
    private const DEFAULT_ONLINE_WINDOW_SECONDS = 1500; // 25 min (deep sleep 10 min + marge)

    private function assertSlug(string $slug): void
    {
        if (!in_array($slug, self::ALLOWED_SLUGS, true)) {
            throw new InvalidArgumentException('Slug galerie invalide: ' . $slug);
        }
    }

    /**
     * Ouvre (ou reactive) une session de synchronisation et renvoie son identifiant serveur.
     *
     * Idempotent sur (slug, device_session) : si le firmware rejoue le start (retry reseau),
     * on reutilise la meme ligne et on remet le compteur a zero pour la nouvelle passe.
     *
     * @return array{id:int, total:int} Identifiant serveur de session + total annonce
     */
    public function startSession(string $slug, int $board, string $deviceSession, int $total, string $firmwareVersion): array
    {
        $this->assertSlug($slug);
        $total = max(0, $total);
        $deviceSession = substr($deviceSession, 0, 64);
        $firmwareVersion = substr($firmwareVersion, 0, 64);

        $table = self::TABLE;
        // ON DUPLICATE KEY : meme device_session => on reprend la session, compteurs reinitialises.
        $sql = "INSERT INTO `{$table}`
                    (slug, board, device_session, firmware_version, total, received, failed, bytes_received, status, started_at, updated_at, finished_at)
                VALUES
                    (:slug, :board, :device_session, :fw, :total, 0, 0, 0, 'active', NOW(), NOW(), NULL)
                ON DUPLICATE KEY UPDATE
                    board = VALUES(board),
                    firmware_version = VALUES(firmware_version),
                    total = VALUES(total),
                    received = 0,
                    failed = 0,
                    bytes_received = 0,
                    status = 'active',
                    started_at = NOW(),
                    updated_at = NOW(),
                    finished_at = NULL";
        $this->execute($sql, [
            ':slug' => $slug,
            ':board' => $board,
            ':device_session' => $deviceSession,
            ':fw' => $firmwareVersion,
            ':total' => $total,
        ]);

        $row = $this->fetchOne(
            "SELECT id, total FROM `{$table}` WHERE slug = :slug AND device_session = :device_session LIMIT 1",
            [':slug' => $slug, ':device_session' => $deviceSession]
        );

        return [
            'id' => (int) ($row['id'] ?? 0),
            'total' => (int) ($row['total'] ?? $total),
        ];
    }

    /**
     * Incremente le compteur de photos recues pour une session active.
     * No-op silencieux si la session est inconnue ou deja cloturee (upload hors session).
     */
    public function incrementReceived(int $sessionId, int $bytes): void
    {
        if ($sessionId <= 0) {
            return;
        }
        $table = self::TABLE;
        $sql = "UPDATE `{$table}`
                   SET received = received + 1,
                       bytes_received = bytes_received + :bytes,
                       updated_at = NOW()
                 WHERE id = :id AND status = 'active'";
        $this->execute($sql, [
            ':bytes' => max(0, $bytes),
            ':id' => $sessionId,
        ]);
    }

    /**
     * Cloture une session et renvoie sa ligne finale (ou null si introuvable).
     *
     * @param int|null $sent   Nombre d'envois reussis rapporte par le firmware (cale `received` si fourni et superieur)
     * @param int|null $failed Nombre d'echecs rapporte par le firmware
     * @param int|null $bytes  Volume total rapporte par le firmware
     *
     * @return array<string, mixed>|null
     */
    public function finishSession(int $sessionId, ?int $sent, ?int $failed, ?int $bytes, string $status = 'completed'): ?array
    {
        if ($sessionId <= 0) {
            return null;
        }
        if (!in_array($status, ['completed', 'aborted'], true)) {
            $status = 'completed';
        }

        $table = self::TABLE;
        $sets = ['status = :status', 'updated_at = NOW()', 'finished_at = NOW()'];
        $params = [':status' => $status, ':id' => $sessionId];

        // Le firmware peut rapporter un total d'envois superieur au compteur serveur
        // (ex. photo auto-corbeille comptee 202, ou course de comptage) : on cale received au max.
        if ($sent !== null) {
            $sets[] = 'received = GREATEST(received, :sent)';
            $params[':sent'] = max(0, $sent);
        }
        if ($failed !== null) {
            $sets[] = 'failed = :failed';
            $params[':failed'] = max(0, $failed);
        }
        if ($bytes !== null) {
            $sets[] = 'bytes_received = GREATEST(bytes_received, :bytes)';
            $params[':bytes'] = max(0, $bytes);
        }

        $sql = "UPDATE `{$table}` SET " . implode(', ', $sets) . ' WHERE id = :id';
        $this->execute($sql, $params);

        return $this->fetchOne("SELECT * FROM `{$table}` WHERE id = :id LIMIT 1", [':id' => $sessionId]);
    }

    /**
     * Session la plus recente pour un slug (active ou cloturee), ou null.
     *
     * @return array<string, mixed>|null
     */
    public function findLatest(string $slug): ?array
    {
        $this->assertSlug($slug);
        $table = self::TABLE;
        return $this->fetchOne(
            "SELECT * FROM `{$table}` WHERE slug = :slug ORDER BY id DESC LIMIT 1",
            [':slug' => $slug]
        );
    }

    /**
     * Etat de synchronisation pour la page de controle (indicateur + jauge).
     *
     * @return array{
     *     slug:string, connection:string, transfer_active:bool, total:int, received:int,
     *     failed:int, percent:int, session_id:int|null, firmware_version:string|null,
     *     started_at:string|null, updated_at:string|null, finished_at:string|null, last_activity:string|null
     * }
     */
    public function getStatus(string $slug): array
    {
        $this->assertSlug($slug);

        $empty = [
            'slug' => $slug,
            'connection' => 'offline',
            'transfer_active' => false,
            'total' => 0,
            'received' => 0,
            'failed' => 0,
            'percent' => 0,
            'session_id' => null,
            'firmware_version' => null,
            'started_at' => null,
            'updated_at' => null,
            'finished_at' => null,
            'last_activity' => null,
        ];

        // Dégradation gracieuse : si la table n'existe pas encore (migration non appliquée)
        // ou en cas d'erreur d'accès, on renvoie un état vide plutôt que de casser la page.
        try {
            $latest = $this->findLatest($slug);
        } catch (\Throwable $e) {
            return $empty;
        }
        if ($latest === null) {
            return $empty;
        }

        $total = (int) ($latest['total'] ?? 0);
        $received = (int) ($latest['received'] ?? 0);
        $failed = (int) ($latest['failed'] ?? 0);
        $active = ($latest['status'] ?? '') === 'active';
        $updatedAt = isset($latest['updated_at']) ? (string) $latest['updated_at'] : null;

        $percent = 0;
        if ($total > 0) {
            $percent = (int) min(100, (int) round(($received / $total) * 100));
        } elseif (!$active) {
            $percent = 100;
        }

        $fresh = $this->isFresh($updatedAt);
        if ($active) {
            $connection = 'transferring';
        } elseif ($fresh) {
            $connection = 'online';
        } else {
            $connection = 'offline';
        }

        return [
            'slug' => $slug,
            'connection' => $connection,
            'transfer_active' => $active,
            'total' => $total,
            'received' => $received,
            'failed' => $failed,
            'percent' => $percent,
            'session_id' => (int) ($latest['id'] ?? 0),
            'firmware_version' => ($latest['firmware_version'] ?? '') !== '' ? (string) $latest['firmware_version'] : null,
            'started_at' => isset($latest['started_at']) ? (string) $latest['started_at'] : null,
            'updated_at' => $updatedAt,
            'finished_at' => isset($latest['finished_at']) ? (string) $latest['finished_at'] : null,
            'last_activity' => $updatedAt,
        ];
    }

    private function isFresh(?string $datetime): bool
    {
        if ($datetime === null || $datetime === '') {
            return false;
        }
        $ts = strtotime($datetime);
        if ($ts === false) {
            return false;
        }
        $window = (int) ($_ENV['GALLERY_SYNC_ONLINE_WINDOW_SECONDS'] ?? self::DEFAULT_ONLINE_WINDOW_SECONDS);
        if ($window <= 0) {
            $window = self::DEFAULT_ONLINE_WINDOW_SECONDS;
        }
        return (time() - $ts) <= $window;
    }
}

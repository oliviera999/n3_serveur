<?php

declare(strict_types=1);

namespace App\Mcp\Health;

/**
 * Règles EXPLICITES du statut global du rapport de santé (`vert` / `orange` / `rouge`).
 *
 * Le statut est le pire niveau déclenché ; aucune règle déclenchée = `vert`. Chaque règle
 * déclenchée produit une raison {code, niveau, message} (liste ordonnée : rouge d'abord).
 * Les seuils sont des constantes publiques, reprises telles quelles dans docs/MCP.md
 * (§ Rapport de santé) : toute modification ici doit y être reportée.
 *
 * | Domaine     | Orange                                        | Rouge                                   |
 * |-------------|-----------------------------------------------|-----------------------------------------|
 * | BDD         | latence > 500 ms                              | injoignable                             |
 * | CRON        | orchestrateur > 5 min, tâches horaires en retard | orchestrateur > 15 min ou jamais vu  |
 * | Appareils   | un appareil de production hors ligne          | aquaponie FFP3 (prod/s3) hors ligne > 6 h |
 * | Erreurs     | ≥ 20 erreurs serveur / 24 h ou ≥ 1 notification critique / 24 h | ≥ 200 erreurs serveur / 24 h |
 * | Galeries    | galerie active sans photo depuis > 48 h       | —                                       |
 * | Disque      | < 10 % ou < 1 Gio libres                      | < 2 % ou < 200 Mio libres               |
 * | TLS         | expire dans < 21 j, ou certificat illisible   | expiré ou expire dans < 7 j             |
 *
 * Un appareil « inactif » (jamais vu, ou silencieux depuis plus de 30 jours) ou une galerie
 * inactive (aucune photo depuis 30 jours) n'influe pas sur le statut : il est seulement listé,
 * pour qu'un module retiré ne laisse pas le rapport orange indéfiniment.
 */
final class HealthRules
{
    public const STATUS_GREEN = 'vert';
    public const STATUS_ORANGE = 'orange';
    public const STATUS_RED = 'rouge';

    public const DB_LATENCY_ORANGE_MS = 500;
    public const CRON_ORANGE_SECONDS = 300;
    public const CRON_RED_SECONDS = 900;
    public const FFP3_OFFLINE_RED_SECONDS = 6 * 3600;
    public const DEVICE_INACTIVE_SECONDS = 30 * 86400;
    public const ERRORS_24H_ORANGE = 20;
    public const ERRORS_24H_RED = 200;
    public const CRITICAL_NOTIFICATIONS_24H_ORANGE = 1;
    public const GALLERY_STALE_SECONDS = 48 * 3600;
    public const GALLERY_INACTIVE_SECONDS = 30 * 86400;
    public const DISK_ORANGE_PERCENT = 10.0;
    public const DISK_ORANGE_BYTES = 1073741824;
    public const DISK_RED_PERCENT = 2.0;
    public const DISK_RED_BYTES = 209715200;
    public const TLS_ORANGE_DAYS = 21;
    public const TLS_RED_DAYS = 7;

    private const RANK = [self::STATUS_GREEN => 0, self::STATUS_ORANGE => 1, self::STATUS_RED => 2];

    /**
     * @param array<string, mixed> $report Rapport construit par {@see HealthReportBuilder}
     * @return array{status: string, reasons: list<array{code: string, level: string, message: string}>}
     */
    public static function evaluate(array $report): array
    {
        $reasons = [];
        $add = static function (string $level, string $code, string $message) use (&$reasons): void {
            $reasons[] = ['code' => $code, 'level' => $level, 'message' => $message];
        };

        // BDD
        $db = is_array($report['database'] ?? null) ? $report['database'] : [];
        if (($db['ok'] ?? false) !== true) {
            $add(self::STATUS_RED, 'bdd_injoignable', 'Base de données injoignable.');
        } elseif ((int) ($db['latency_ms'] ?? 0) > self::DB_LATENCY_ORANGE_MS) {
            $add(self::STATUS_ORANGE, 'bdd_lente', sprintf('Latence BDD élevée (%d ms > %d ms).', (int) $db['latency_ms'], self::DB_LATENCY_ORANGE_MS));
        }

        // CRON
        $cron = is_array($report['cron'] ?? null) ? $report['cron'] : [];
        $orchestrator = is_array($cron['orchestrator'] ?? null) ? $cron['orchestrator'] : [];
        $age = isset($orchestrator['age_seconds']) && is_int($orchestrator['age_seconds']) ? $orchestrator['age_seconds'] : null;
        if ($age === null) {
            $add(self::STATUS_RED, 'cron_jamais_vu', 'Aucun passage du CRON détecté (crontab absente ou état illisible).');
        } elseif ($age > self::CRON_RED_SECONDS) {
            $add(self::STATUS_RED, 'cron_arrete', sprintf('CRON arrêté : dernier passage il y a %s.', self::duration($age)));
        } elseif ($age > self::CRON_ORANGE_SECONDS) {
            $add(self::STATUS_ORANGE, 'cron_en_retard', sprintf('CRON en retard : dernier passage il y a %s.', self::duration($age)));
        }
        foreach ((array) ($cron['tasks'] ?? []) as $task) {
            if (is_array($task) && ($task['id'] ?? '') === 'taches_horaires' && ($task['late'] ?? false) === true) {
                $add(self::STATUS_ORANGE, 'cron_horaire_en_retard', sprintf(
                    'Tâches horaires du CRON en retard de %s (supervision hors ligne, digest).',
                    self::duration((int) ($task['delay_seconds'] ?? 0))
                ));
            }
        }

        // Appareils
        foreach ((array) ($report['devices'] ?? []) as $device) {
            if (!is_array($device) || ($device['active'] ?? true) !== true || ($device['online'] ?? null) !== false) {
                continue;
            }
            $label = (string) ($device['label'] ?? $device['id'] ?? '?');
            $silence = isset($device['silent_seconds']) && is_int($device['silent_seconds']) ? $device['silent_seconds'] : null;
            $isFfp3 = ($device['family'] ?? '') === 'ffp3';
            if ($isFfp3 && $silence !== null && $silence > self::FFP3_OFFLINE_RED_SECONDS) {
                $add(self::STATUS_RED, 'aquaponie_hors_ligne', sprintf('%s hors ligne depuis %s (êtres vivants : vérifier sur place).', $label, self::duration($silence)));
            } else {
                $add(self::STATUS_ORANGE, 'appareil_hors_ligne', sprintf('%s hors ligne%s.', $label, $silence !== null ? ' depuis ' . self::duration($silence) : ''));
            }
        }

        // Erreurs / alertes
        $errors = is_array($report['errors'] ?? null) ? $report['errors'] : [];
        $errors24 = (int) ($errors['server_errors_24h']['occurrences'] ?? 0);
        $critical24 = (int) ($errors['notifications_24h']['critical'] ?? 0);
        if ($errors24 >= self::ERRORS_24H_RED) {
            $add(self::STATUS_RED, 'erreurs_massives', sprintf('%d erreurs serveur en 24 h (≥ %d).', $errors24, self::ERRORS_24H_RED));
        } elseif ($errors24 >= self::ERRORS_24H_ORANGE) {
            $add(self::STATUS_ORANGE, 'erreurs_frequentes', sprintf('%d erreurs serveur en 24 h (≥ %d).', $errors24, self::ERRORS_24H_ORANGE));
        }
        if ($critical24 >= self::CRITICAL_NOTIFICATIONS_24H_ORANGE) {
            $add(self::STATUS_ORANGE, 'alertes_critiques', sprintf('%d notification(s) critique(s) envoyée(s) en 24 h.', $critical24));
        }

        // Galeries
        foreach ((array) ($report['galleries'] ?? []) as $gallery) {
            if (is_array($gallery) && ($gallery['active'] ?? false) === true && ($gallery['stale'] ?? false) === true) {
                $add(self::STATUS_ORANGE, 'galerie_muette', sprintf(
                    'Galerie %s : aucune photo depuis %s.',
                    (string) ($gallery['label'] ?? $gallery['slug'] ?? '?'),
                    self::duration((int) ($gallery['latest_photo_age_seconds'] ?? 0))
                ));
            }
        }

        // Disque
        $disk = is_array($report['disk'] ?? null) ? $report['disk'] : [];
        if (isset($disk['free_bytes']) && is_int($disk['free_bytes'])) {
            $free = $disk['free_bytes'];
            $pct = isset($disk['free_percent']) && is_numeric($disk['free_percent']) ? (float) $disk['free_percent'] : null;
            $text = sprintf('Espace disque libre : %s%s.', self::bytes($free), $pct !== null ? sprintf(' (%.1f %%)', $pct) : '');
            if ($free < self::DISK_RED_BYTES || ($pct !== null && $pct < self::DISK_RED_PERCENT)) {
                $add(self::STATUS_RED, 'disque_plein', $text);
            } elseif ($free < self::DISK_ORANGE_BYTES || ($pct !== null && $pct < self::DISK_ORANGE_PERCENT)) {
                $add(self::STATUS_ORANGE, 'disque_bas', $text);
            }
        }

        // TLS
        $tls = $report['tls'] ?? null;
        if (is_array($tls)) {
            if (($tls['ok'] ?? false) !== true) {
                $add(self::STATUS_ORANGE, 'tls_illisible', 'Certificat TLS illisible : ' . (string) ($tls['error'] ?? 'erreur inconnue') . '.');
            } else {
                $days = (int) ($tls['days_remaining'] ?? 0);
                if ($days < self::TLS_RED_DAYS) {
                    $add(self::STATUS_RED, 'tls_expire', $days < 0
                        ? sprintf('Certificat TLS de %s EXPIRÉ depuis %d j.', (string) $tls['host'], -$days)
                        : sprintf('Certificat TLS de %s expire dans %d j (AutoSSL cPanel ?).', (string) $tls['host'], $days));
                } elseif ($days < self::TLS_ORANGE_DAYS) {
                    $add(self::STATUS_ORANGE, 'tls_bientot', sprintf('Certificat TLS de %s expire dans %d j.', (string) $tls['host'], $days));
                }
            }
        }

        usort($reasons, static fn (array $a, array $b): int => self::RANK[$b['level']] <=> self::RANK[$a['level']]);
        $status = self::STATUS_GREEN;
        foreach ($reasons as $reason) {
            if (self::RANK[$reason['level']] > self::RANK[$status]) {
                $status = $reason['level'];
            }
        }

        return ['status' => $status, 'reasons' => $reasons];
    }

    public static function duration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        if ($seconds < 120) {
            return $seconds . ' s';
        }
        if ($seconds < 7200) {
            return (int) round($seconds / 60) . ' min';
        }
        if ($seconds < 3 * 86400) {
            return (int) round($seconds / 3600) . ' h';
        }

        return (int) round($seconds / 86400) . ' j';
    }

    public static function bytes(int $bytes): string
    {
        $units = ['o', 'Kio', 'Mio', 'Gio', 'Tio'];
        $value = (float) max(0, $bytes);
        $i = 0;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return ($i === 0 ? (string) (int) $value : number_format($value, 1, ',', '')) . ' ' . $units[$i];
    }
}

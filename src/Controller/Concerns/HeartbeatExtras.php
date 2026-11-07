<?php

declare(strict_types=1);

namespace App\Controller\Concerns;

use App\Repository\DeviceEventRepository;

/**
 * Lecture des champs ENRICHIS du heartbeat (firmware ffp5cs ≥ 15.34, n3pp ≥ 4.76, msp ≥ 2.79) et du
 * journal de bord qu'il transporte. Partagé entre le heartbeat FFP3 et le handler legacy n3pp/msp.
 *
 * Champs POST optionnels (tous facultatifs, un firmware antérieur n'en envoie aucun) :
 *   rssi=<dBm>  version=<fw>  rst=<POWERON|SW|PANIC|...>  part=<app0|app1>  ota=<valid|pending|new|...>
 *   fails=<boots ratés consécutifs>  safe=<0|1>  clk=<décalage firmware−serveur mesuré, s>
 *   wifi_ms=<durée connexion WiFi>  wifi_tries=<tentatives>  board=<identifiant carte>
 *   boot=1 (premier heartbeat après boot) ; log=<JSON compact d'événements, même format que /ota-report>
 */
final class HeartbeatExtras
{
    public const MAX_LOG_EVENTS = 32;

    /**
     * Colonnes enrichies prêtes pour {@see \App\Repository\HeartbeatRepository::insert()}.
     *
     * @param array<string, mixed> $params
     * @return array<string, scalar|null>
     */
    public static function columns(array $params): array
    {
        $get = static fn (string $k): string => isset($params[$k]) && is_scalar($params[$k]) ? trim((string) $params[$k]) : '';
        $int = static fn (string $v): ?int => $v !== '' && preg_match('/^-?\d{1,10}$/', $v) === 1 ? (int) $v : null;
        $tok = static fn (string $v, int $max): ?string => $v !== '' ? substr(preg_replace('/[^A-Za-z0-9_.:-]/', '', $v) ?? '', 0, $max) : null;
        $pct = static fn (string $v): ?int => $v !== '' && preg_match('/^\d{1,3}$/', $v) === 1 && (int) $v <= 100 ? (int) $v : null;

        return [
            'rssi' => $int($get('rssi')),
            'version' => $tok($get('version'), 30),
            'resetReason' => $tok($get('rst'), 16),
            'partition' => $tok($get('part'), 8),
            'otaState' => $tok($get('ota'), 16),
            'failedBoots' => $int($get('fails')),
            'safeMode' => $get('safe') === '' ? null : ($get('safe') === '1' ? 1 : 0),
            'clockOffsetS' => $int($get('clk')),
            'wifiConnectMs' => $int($get('wifi_ms')),
            'wifiAttempts' => $int($get('wifi_tries')),
            // 6.60.0 / ffp5cs 15.35 : ressources (plus grand bloc libre, % idle CPU, % NVS utilisée)
            'maxBlock' => $int($get('blk')),
            'idlePct' => $pct($get('idle')),
            'nvsUsedPct' => $pct($get('nvs')),
        ];
    }

    /**
     * Événements à ranger dans `device_events` : journal de bord (`log`) + événement `boot` si le
     * heartbeat est le premier après démarrage (`boot=1`).
     *
     * @param array<string, mixed> $params
     * @return list<array{source: string, seq: ?int, device_ts: ?int, uptime_s: ?int, event: string, detail: ?string}>
     */
    public static function events(array $params): array
    {
        $get = static fn (string $k): string => isset($params[$k]) && is_scalar($params[$k]) ? trim((string) $params[$k]) : '';
        $out = [];
        $uptime = ctype_digit($get('uptime')) ? (int) $get('uptime') : null;

        if ($get('boot') === '1') {
            $cols = self::columns($params);
            $detail = sprintf(
                'rst=%s part=%s ota=%s fails=%s safe=%s reboots=%s',
                $cols['resetReason'] ?? '?',
                $cols['partition'] ?? '?',
                $cols['otaState'] ?? '?',
                $cols['failedBoots'] ?? '?',
                $cols['safeMode'] ?? '?',
                ctype_digit($get('reboots')) ? $get('reboots') : '?'
            );
            $out[] = ['source' => DeviceEventRepository::SOURCE_BOOT, 'seq' => null, 'device_ts' => null, 'uptime_s' => $uptime, 'event' => 'boot', 'detail' => $detail];
        }

        $decoded = json_decode($get('log'), true);
        if (is_array($decoded)) {
            foreach (array_slice(array_values($decoded), 0, self::MAX_LOG_EVENTS) as $item) {
                if (!is_array($item) || !isset($item['e']) || !is_scalar($item['e'])) {
                    continue;
                }
                $event = preg_replace('/[^a-z0-9_.-]/i', '', substr((string) $item['e'], 0, 32)) ?? '';
                if ($event === '') {
                    continue;
                }
                $out[] = [
                    'source' => DeviceEventRepository::SOURCE_HEARTBEAT_LOG,
                    'seq' => isset($item['q']) && is_numeric($item['q']) ? (int) $item['q'] : null,
                    'device_ts' => isset($item['t']) && is_numeric($item['t']) ? (int) $item['t'] : null,
                    'uptime_s' => isset($item['u']) && is_numeric($item['u']) ? (int) $item['u'] : $uptime,
                    'event' => $event,
                    'detail' => isset($item['d']) && is_scalar($item['d']) ? substr((string) $item['d'], 0, 255) : null,
                ];
            }
        }

        return $out;
    }
}

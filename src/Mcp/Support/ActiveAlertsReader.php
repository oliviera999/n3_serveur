<?php

declare(strict_types=1);

namespace App\Mcp\Support;

/**
 * Lecture SEULE des alertes « en cours » persistées par la supervision :
 *  - incidents de disponibilité ouverts (`var/cache/availability_state.json`,
 *    {@see \App\Service\Availability\AvailabilityNotifier}) ;
 *  - latches des alertes dérivées (`var/cache/derived_alerts_{ffp3,n3pp,msp1}.json`,
 *    {@see \App\Service\DerivedAlert\DerivedAlertStateStore}) : trop-plein, batterie,
 *    sol sec, gel, canicule, pluie, pompe bloquée.
 *
 * Aucun fichier n'est écrit ; un fichier absent ou corrompu donne une liste vide.
 * Les fichiers sont ceux de l'environnement par défaut du CRON (le CRON ne bascule pas).
 */
final class ActiveAlertsReader
{
    /** @var array<string, array{family: string, key: string, label: string, severity: string}> */
    private const LATCHES = [
        'batteryLow' => ['family' => '', 'key' => 'battery-low', 'label' => 'Batterie faible (PontDiv < SeuilPontDiv)', 'severity' => 'critical'],
        'soilDry' => ['family' => 'n3pp', 'key' => 'soil-dry', 'label' => 'Sol sec (HumidMoy < SeuilSec)', 'severity' => 'alert'],
        'frost' => ['family' => 'msp1', 'key' => 'frost', 'label' => 'Gel (TempAirExt sous le seuil)', 'severity' => 'alert'],
        'heat' => ['family' => 'msp1', 'key' => 'heat', 'label' => 'Canicule (TempAirExt au-dessus du seuil)', 'severity' => 'alert'],
        'rain' => ['family' => 'msp1', 'key' => 'rain', 'label' => 'Pluie détectée', 'severity' => 'info'],
    ];

    private string $cacheDir;

    private string $pumpRestartFlag;

    public function __construct(?string $cacheDir = null, ?string $pumpRestartFlag = null)
    {
        $this->cacheDir = $cacheDir ?? dirname(__DIR__, 3) . '/var/cache';
        // Même emplacement que CronOrchestrator / RestartPumpCommand.
        $this->pumpRestartFlag = $pumpRestartFlag ?? sys_get_temp_dir() . '/pump_restart_scheduled.flag';
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function active(): array
    {
        $alerts = [];

        foreach ($this->load('availability_state.json') as $key => $record) {
            if (is_array($record) && ($record['status'] ?? null) === 'offline') {
                $since = isset($record['since']) && is_numeric($record['since']) ? (int) $record['since'] : null;
                $alerts[] = [
                    'key' => (string) $key,
                    'family' => self::familyFromKey((string) $key),
                    'type' => 'disponibilite',
                    'label' => 'Appareil hors ligne / silencieux',
                    'severity' => 'critical',
                    'since' => $since !== null ? date('Y-m-d H:i:s', $since) : null,
                ];
            }
        }

        foreach (['ffp3', 'n3pp', 'msp1'] as $family) {
            $state = $this->load('derived_alerts_' . $family . '.json');
            foreach (self::LATCHES as $stateKey => $meta) {
                if (($state[$stateKey] ?? false) === true && ($meta['family'] === '' || $meta['family'] === $family)) {
                    $alerts[] = [
                        'key' => $family . ':' . $meta['key'],
                        'family' => $family,
                        'type' => 'derivee',
                        'label' => $meta['label'],
                        'severity' => $meta['severity'],
                        'since' => null,
                    ];
                }
            }
            $flood = $state['flood'] ?? null;
            if (is_array($flood) && ($flood['inFlood'] ?? false) === true) {
                $alerts[] = [
                    'key' => 'ffp3:flood',
                    'family' => 'ffp3',
                    'type' => 'derivee',
                    'label' => 'Trop-plein aquarium (EauAquarium < limFlood)',
                    'severity' => 'critical',
                    'since' => isset($flood['lastEmailTs']) && (int) $flood['lastEmailTs'] > 0
                        ? date('Y-m-d H:i:s', (int) $flood['lastEmailTs']) : null,
                ];
            }
            // Capteur absent / figé (Ffp3DerivedAlertService::checkSensorPresence, 6.56.0).
            foreach ((array) ($state['sensors'] ?? []) as $column => $sensor) {
                $status = is_array($sensor) ? (string) ($sensor['status'] ?? 'ok') : 'ok';
                if ($status !== 'absent' && $status !== 'frozen') {
                    continue;
                }
                $alerts[] = [
                    'key' => $family . ':sensor-' . $status . ':' . $column,
                    'family' => $family,
                    'type' => 'derivee',
                    'label' => $status === 'absent'
                        ? sprintf('Capteur %s absent : plus de valeur (supervision marées / niveau / trop-plein aveugle)', $column)
                        : sprintf('Capteur %s figé : même valeur répétée (mesure non fiable)', $column),
                    'severity' => 'critical',
                    'since' => isset($sensor['since']) && is_string($sensor['since']) ? $sensor['since'] : null,
                ];
            }
            $pump = $state['pump'] ?? null;
            if (is_array($pump) && ($pump['stuckAlerted'] ?? false) === true) {
                $alerts[] = [
                    'key' => 'n3pp:pump-stuck',
                    'family' => $family,
                    'type' => 'derivee',
                    'label' => 'Pompe d\'irrigation restée en marche (bloquée ?)',
                    'severity' => 'alert',
                    'since' => null,
                ];
            }
        }

        $flag = $this->pumpRestartFlag;
        if (is_file($flag)) {
            $scheduled = (int) @file_get_contents($flag);
            $alerts[] = [
                'key' => 'ffp3:tide-problem',
                'family' => 'ffp3',
                'type' => 'securite',
                'label' => 'Problème de marée : pompe aquarium coupée par le CRON, redémarrage automatique programmé (5 min)',
                'severity' => 'critical',
                'since' => $scheduled > 0 ? date('Y-m-d H:i:s', $scheduled) : null,
            ];
        }

        return $alerts;
    }

    private static function familyFromKey(string $key): string
    {
        $lower = strtolower($key);
        foreach (['ffp3', 'n3pp', 'msp1'] as $family) {
            if (str_contains($lower, $family)) {
                return $family;
            }
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private function load(string $file): array
    {
        $path = $this->cacheDir . '/' . $file;
        if (!is_file($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        return is_array($decoded) ? $decoded : [];
    }
}

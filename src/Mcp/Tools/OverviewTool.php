<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Config\PglConfig;
use App\Mcp\McpConfig;
use App\Mcp\McpContext;
use App\Mcp\Support\ActiveAlertsReader;
use App\Mcp\Support\FieldCatalog;
use App\Mcp\Timeline\ContactTimelineBuilder;
use App\Mcp\ToolResult;
use App\Repository\McpReadRepository;
use App\Service\OfflineThresholdResolver;

/**
 * `iot_overview` — état synthétique de chaque famille : en ligne / hors ligne, firmware,
 * dernière mesure résumée, alertes actives.
 */
final class OverviewTool extends AbstractTool
{
    /** Seuil hors-ligne du banc énergie (mesure toutes les 10 s) — miroir EnergieRealtimeDataProvider. */
    private const ENERGIE_OFFLINE_SECONDS = 90;

    public function __construct(
        McpConfig $config,
        private McpReadRepository $repo,
        private OfflineThresholdResolver $thresholds,
        private ActiveAlertsReader $alerts,
        private ?ContactTimelineBuilder $timeline = null,
    ) {
        parent::__construct($config);
    }

    public function name(): string
    {
        return 'iot_overview';
    }

    public function title(): string
    {
        return 'Vue d\'ensemble des modules IoT';
    }

    public function description(): string
    {
        return 'Point de départ recommandé. Pour chaque module (aquaponie FFP3, serre N3PP, météo MSP1, banc énergie, '
            . 'compteur Poissonglouton) : en ligne ou hors ligne (âge de la dernière mesure et du dernier heartbeat comparés '
            . 'au seuil dérivé du temps de veille configuré), version du firmware, dernière mesure résumée (unités converties, '
            . 'niveaux d\'eau en cm) et nombre d\'alertes actives. Un module en veille profonde peut sembler silencieux '
            . 'quelques minutes : se fier à « online » qui tient compte de la veille. Pour un module hors ligne, '
            . '« contact_loss » résume le dernier contact, les réveils manqués et la cause la plus probable '
            . '(détail et preuves : iot_contact_timeline).';
    }

    public function inputSchema(): array
    {
        return $this->objectSchema(['env' => $this->envProperty()]);
    }

    public function call(array $arguments, McpContext $context): ToolResult
    {
        return $this->inEnv($arguments, function (string $env): array {
            $modules = [];
            $activeAlerts = $this->alerts->active();
            foreach (FieldCatalog::FAMILIES as $family) {
                $modules[] = $this->family($family, $env, $activeAlerts);
            }
            $modules[] = $this->pgl();

            return [
                'generated_at' => date('Y-m-d H:i:s'),
                'modules' => $modules,
                'active_alerts_total' => count($activeAlerts),
            ];
        });
    }

    /**
     * @param list<array<string, mixed>> $activeAlerts
     * @return array<string, mixed>
     */
    private function family(string $family, string $env, array $activeAlerts): array
    {
        $out = [
            'family' => $family,
            'label' => FieldCatalog::label($family),
            'is_production' => FieldCatalog::isProductionTarget($family, $env),
        ];
        $prefix = $family === 'msp1' ? 'msp1:' : $family . ':';
        $alertCount = count(array_filter(
            $activeAlerts,
            static fn (array $a): bool => str_starts_with((string) ($a['key'] ?? ''), $prefix)
                || strtolower((string) ($a['family'] ?? '')) === $family
        ));
        $out['active_alerts'] = $alertCount;
        try {
            $latest = $this->repo->latest($family);
        } catch (\Throwable) {
            return $out + ['status' => 'indisponible', 'online' => null, 'note' => 'Table de mesures absente ou illisible.'];
        }
        if ($latest === null) {
            return $out + ['status' => 'jamais_vu', 'online' => null, 'note' => 'Aucune mesure enregistrée.'];
        }

        $lastReading = (string) $latest['reading_time'];
        $dataAge = self::ageSeconds($lastReading);
        $threshold = $family === 'energie'
            ? self::ENERGIE_OFFLINE_SECONDS
            : $this->thresholds->resolveForFamily(strtoupper($family === 'msp1' ? 'MSP1' : $family));

        $heartbeat = null;
        $hbAge = null;
        try {
            $hb = $this->repo->lastHeartbeat($family);
            if ($hb !== null) {
                $hbAge = self::ageSeconds((string) ($hb['reading_time'] ?? ''));
                $heartbeat = [
                    'last' => $hb['reading_time'] ?? null,
                    'age_seconds' => $hbAge,
                    'uptime_s' => isset($hb['uptime']) ? (int) $hb['uptime'] : null,
                    'free_heap' => isset($hb['freeHeap']) ? (int) $hb['freeHeap'] : null,
                    'reboots' => isset($hb['reboots']) ? (int) $hb['reboots'] : null,
                    'rssi' => isset($hb['rssi']) ? (int) $hb['rssi'] : null,
                ];
            }
        } catch (\Throwable) {
        }

        $freshest = min($dataAge ?? PHP_INT_MAX, $hbAge ?? PHP_INT_MAX);
        $online = $freshest !== PHP_INT_MAX && $freshest <= $threshold;

        $summary = [];
        foreach (FieldCatalog::summaryFields($family) as $field) {
            $value = FieldCatalog::present($family, $field, $latest[$field] ?? null);
            $summary[$field] = $value === null ? null : ['value' => $value, 'unit' => FieldCatalog::unit($family, $field)];
        }
        $result = $out + [
            'status' => $online ? 'en_ligne' : 'hors_ligne',
            'online' => $online,
            'last_reading' => $lastReading,
            'last_reading_age_seconds' => $dataAge,
            'offline_threshold_seconds' => $threshold,
            'heartbeat' => $heartbeat,
            'firmware_version' => isset($latest['version']) && $latest['version'] !== '' ? (string) $latest['version'] : null,
            'latest' => $summary,
        ];
        if (!$online && $this->timeline !== null && $family !== 'energie') {
            $result['contact_loss'] = self::contactLossSummary($this->timeline, $family, $env);
        }

        return $result;
    }

    /**
     * Résumé « perte de contact » (dernier contact, réveils manqués, cause la plus probable) pour un
     * module hors ligne — partagé avec le rapport de santé. Jamais d'exception : null si indisponible.
     *
     * @return array<string, mixed>|null
     */
    public static function contactLossSummary(ContactTimelineBuilder $timeline, string $family, string $env): ?array
    {
        try {
            $now = new \DateTimeImmutable('now');
            $t = $timeline->build($family, $env, $now->modify('-24 hours'), $now);
        } catch (\Throwable) {
            return null;
        }
        $top = $t['classification']['causes'][0] ?? null;

        return [
            'last_contact' => $t['last_contact'],
            'last_authenticated_contact' => $t['last_authenticated_contact'],
            'missed_wakes' => $t['expected_wake']['missed_wakes'],
            'next_expected_at' => $t['expected_wake']['next_expected_at'],
            'verdict' => $t['classification']['verdict'],
            'top_cause' => $top !== null ? ['cause' => $top['cause'], 'probability' => $top['probability'], 'for' => array_slice($top['for'], 0, 3)] : null,
            'detail_tool' => 'iot_contact_timeline',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pgl(): array
    {
        $stats = $this->repo->pglStats(1, 1);
        if (($stats['available'] ?? false) !== true) {
            return ['family' => 'pgl', 'label' => 'Poissonglouton (compteur de déchets plastiques)', 'status' => 'indisponible', 'online' => null];
        }
        $lastEvent = is_array($stats['latest_event']) ? (string) ($stats['latest_event']['event_time'] ?? '') : '';
        $lastHb = $stats['last_heartbeat'];
        $age = min(self::ageSeconds($lastEvent) ?? PHP_INT_MAX, self::ageSeconds($lastHb) ?? PHP_INT_MAX);

        return [
            'family' => 'pgl',
            'label' => 'Poissonglouton (compteur de déchets plastiques)',
            'is_production' => true,
            'status' => $age <= PglConfig::ONLINE_THRESHOLD_SECONDS ? 'en_ligne' : ($age === PHP_INT_MAX ? 'jamais_vu' : 'hors_ligne'),
            'online' => $age === PHP_INT_MAX ? null : $age <= PglConfig::ONLINE_THRESHOLD_SECONDS,
            'last_seen_age_seconds' => $age === PHP_INT_MAX ? null : $age,
            'firmware_version' => is_array($stats['latest_event']) ? ($stats['latest_event']['fw_version'] ?? null) : null,
            'total_count' => $stats['total'],
            'today_count' => $stats['today'],
            'note' => 'Tables PGL uniques (non suffixées par environnement).',
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\McpConfig;
use App\Mcp\McpContext;
use App\Mcp\Support\FieldCatalog;
use App\Mcp\Support\TimeWindow;
use App\Mcp\ToolResult;
use App\Repository\McpReadRepository;
use App\Service\TideAnalysisService;
use App\Util\Ffp3WaterLevelUnit;

/**
 * `iot_tides` — analyse des marées FFP3 via {@see TideAnalysisService::computeFromRows()}.
 */
final class TidesTool extends AbstractTool
{
    /** Fenêtre maximale de l'analyse (lignes brutes en mémoire). */
    private const MAX_SPAN_DAYS = 14;

    public function __construct(
        McpConfig $config,
        private McpReadRepository $repo,
        private TideAnalysisService $tides,
    ) {
        parent::__construct($config);
    }

    public function name(): string
    {
        return 'iot_tides';
    }

    public function title(): string
    {
        return 'Analyse des marées (aquaponie)';
    }

    public function description(): string
    {
        return 'Analyse des cycles de marée de l\'aquaponie FFP3 (la pompe aquarium envoie l\'eau vers le bac potager puis '
            . 'elle redescend) sur une période (défaut 24 h, max 14 j) : nombre de cycles complets, marnage moyen (amplitude '
            . 'en cm), fréquence (cycles par heure), variations de la réserve (cm), statistiques de diffMaree (mm), et '
            . 'derniers événements de marée signalés par le firmware. Peu ou pas de cycles = pompe arrêtée, bouchée ou capteur '
            . 'figé ; le CRON coupe la pompe 5 min si l\'écart-type du niveau s\'effondre (problème de marée).';
    }

    public function inputSchema(): array
    {
        $allowed = array_values(array_intersect($this->config->allowedEnvs(), ['prod', 'test', 'test3', 's3', 's3test']));
        $env = $this->envProperty();
        if ($allowed !== []) {
            $env['enum'] = $allowed;
            $env['default'] = in_array('prod', $allowed, true) ? 'prod' : $allowed[0];
        }

        return $this->objectSchema(array_merge($this->windowProperties('-24h'), ['env' => $env]));
    }

    public function call(array $arguments, McpContext $context): ToolResult
    {
        $window = TimeWindow::parse(
            isset($arguments['from']) ? (string) $arguments['from'] : null,
            isset($arguments['to']) ? (string) $arguments['to'] : null,
            86400,
            self::MAX_SPAN_DAYS
        );

        return $this->inEnv($arguments, function () use ($window): array {
            $rows = $this->repo->rawRows('ffp3', ['EauAquarium', 'EauReserve', 'diffMaree'], $window->fromSql(), $window->toSql());
            $truncated = count($rows) >= McpReadRepository::MAX_RAW_ROWS;
            $analysis = $this->tides->computeFromRows(Ffp3WaterLevelUnit::scaleSensorRowsFromMmToCm($rows));
            $events = array_map(static fn (array $e): array => [
                'event' => (string) $e['tideEvent'],
                'at' => (string) $e['reading_time'],
                'EauAquarium_cm' => FieldCatalog::present('ffp3', 'EauAquarium', $e['EauAquarium'] ?? null),
                'diffMaree_mm' => FieldCatalog::present('ffp3', 'diffMaree', $e['diffMaree'] ?? null),
            ], $this->repo->tideEvents($window->fromSql(), $window->toSql(), 30));

            return [
                'family' => 'ffp3',
                'window' => $window->describe(),
                'readings' => count($rows),
                'truncated' => $truncated,
                'cycles' => $analysis['cycles'] ?? 0,
                'tidal_range_mean_cm' => self::round($analysis['marnage_moyen'] ?? null),
                'tides_per_hour' => self::round($analysis['frequence_marees'] ?? null),
                'reserve_cm' => [
                    'rise' => self::round($analysis['reserve_pos'] ?? null),
                    'drop' => self::round($analysis['reserve_neg'] ?? null),
                    'net' => self::round($analysis['reserve_var'] ?? null),
                ],
                'diffMaree_mm' => array_map([self::class, 'round'], (array) ($analysis['diff_maree'] ?? [])),
                'firmware_events' => $events,
            ];
        }, 'ffp3');
    }

    public static function round(mixed $value): ?float
    {
        return is_numeric($value) ? round((float) $value, 3) : null;
    }
}

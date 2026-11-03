<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Config\Paths;
use App\Mcp\McpConfig;
use App\Mcp\McpContext;
use App\Mcp\Support\FieldCatalog;
use App\Mcp\ToolResult;
use App\Repository\McpReadRepository;
use App\Service\Contract\ContractAlignmentService;

/**
 * `iot_contract_check` — alignement contrat firmware ↔ serveur et versions firmware.
 */
final class ContractCheckTool extends AbstractTool
{
    /** Dossier OTA → famille et canal (lecture de metadata.json, champs publics uniquement). */
    private const OTA_CHANNELS = [
        'ffp3' => ['esp32-wroom' => 'prod', 'esp32-wroom-beta' => 'test', 'esp32-s3' => 'prod (s3)', 'esp32-s3-test' => 'test (s3)'],
        'n3pp' => ['n3pp' => 'prod', 'n3pp-test' => 'test'],
        'msp1' => ['msp' => 'prod', 'msp-test' => 'test'],
    ];

    private string $projectRoot;

    public function __construct(
        McpConfig $config,
        private ContractAlignmentService $contracts,
        private McpReadRepository $repo,
        ?string $projectRoot = null,
    ) {
        parent::__construct($config);
        $this->projectRoot = $projectRoot ?? Paths::getProjectRoot();
    }

    public function name(): string
    {
        return 'iot_contract_check';
    }

    public function title(): string
    {
        return 'Alignement firmware ↔ serveur';
    }

    public function description(): string
    {
        return 'Compare les contrats JSON (contracts/*.json : champs envoyés par chaque firmware, GPIO) au code serveur '
            . '(champs parsés par les contrôleurs POST, *GpioMap) : statut ok / warn / error et écarts détaillés. Ajoute, pour '
            . 'FFP3 / N3PP / MSP1, la version firmware vue dans la dernière mesure et la version publiée en OTA (metadata.json) '
            . 'pour repérer un module non mis à jour ou une version inattendue. Lecture seule.';
    }

    public function inputSchema(): array
    {
        return $this->objectSchema([
            'contract' => [
                'type' => 'string',
                'enum' => ['ffp3', 'n3pp', 'msp1', 'energie', 'pgl'],
                'description' => 'Contrat à vérifier (défaut : tous).',
            ],
            'env' => $this->envProperty(),
        ]);
    }

    public function call(array $arguments, McpContext $context): ToolResult
    {
        $only = isset($arguments['contract']) ? (string) $arguments['contract'] : null;

        return $this->inEnv($arguments, function () use ($only): array {
            $reports = $only !== null ? array_filter([$this->contracts->checkById($only)]) : $this->contracts->checkAll();
            $out = [];
            foreach ($reports as $report) {
                $out[] = [
                    'id' => $report['id'],
                    'label' => $report['label'],
                    'status' => $report['status'],
                    'issues' => $report['issues'],
                    'missing_in_code' => $report['fields']['missing_in_code'] ?? [],
                    'missing_in_contract' => $report['fields']['missing_in_contract'] ?? [],
                ];
            }

            $firmware = [];
            foreach (self::OTA_CHANNELS as $family => $channels) {
                if ($only !== null && $only !== $family) {
                    continue;
                }
                $seen = null;
                try {
                    $latest = $this->repo->latest($family);
                    $seen = is_array($latest) ? ($latest['version'] ?? null) : null;
                } catch (\Throwable) {
                }
                $published = [];
                foreach ($channels as $dir => $channel) {
                    $version = $this->otaVersion($dir);
                    if ($version !== null) {
                        $published[] = ['channel' => $channel, 'directory' => $dir, 'version' => $version];
                    }
                }
                $firmware[$family] = [
                    'label' => FieldCatalog::label($family),
                    'running_version' => $seen,
                    'published_ota' => $published,
                    'up_to_date' => $seen !== null && in_array($seen, array_column($published, 'version'), true),
                ];
            }

            return ['contracts' => $out, 'firmware' => $firmware];
        });
    }

    private function otaVersion(string $dir): ?string
    {
        // FFP3 : un metadata.json racine avec canaux prod/test par carte (esp32-wroom, esp32-s3).
        $ffp3 = ['esp32-wroom' => ['prod', 'esp32-wroom'], 'esp32-wroom-beta' => ['test', 'esp32-wroom'],
            'esp32-s3' => ['prod', 'esp32-s3'], 'esp32-s3-test' => ['test', 'esp32-s3']];
        if (isset($ffp3[$dir])) {
            $root = json_decode((string) @file_get_contents($this->projectRoot . '/ota/metadata.json'), true);
            [$channel, $board] = $ffp3[$dir];
            $version = is_array($root) ? ($root['channels'][$channel][$board]['version'] ?? null) : null;

            return is_scalar($version) ? (string) $version : null;
        }
        $path = $this->projectRoot . '/ota/' . $dir . '/metadata.json';
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($path), true);

        return is_array($data) && is_scalar($data['version'] ?? null) ? (string) $data['version'] : null;
    }
}

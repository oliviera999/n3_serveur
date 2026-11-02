<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\McpConfig;
use App\Mcp\McpContext;
use App\Mcp\Support\FieldCatalog;
use App\Mcp\ToolResult;
use App\Repository\McpReadRepository;

/**
 * `iot_outputs_state` — état des sorties GPIO (lecture seule), avec libellés.
 */
final class OutputsStateTool extends AbstractTool
{
    /** GPIO porteurs de données personnelles (adresse e-mail) : jamais exposés. */
    public const PERSONAL_GPIOS = [100, 101];

    public function __construct(McpConfig $config, private McpReadRepository $repo)
    {
        parent::__construct($config);
    }

    public function name(): string
    {
        return 'iot_outputs_state';
    }

    public function title(): string
    {
        return 'État des sorties GPIO';
    }

    public function description(): string
    {
        return 'État ENREGISTRÉ côté serveur des sorties d\'un module (actionneurs : pompes, chauffage, lumière, relais ; et '
            . 'paramètres : seuils, horaires de nourrissage, veille…) avec, pour chaque GPIO, son libellé, sa valeur, la date '
            . 'du dernier changement et sa source (web, cron, esp32 — FFP3 uniquement). C\'est la consigne que l\'ESP32 lira '
            . 'à son prochain réveil : l\'état réel mesuré est dans iot_latest (etatPompeAqua…). Les GPIO d\'adresse e-mail '
            . '(100, 101) ne sont jamais renvoyés. Lecture seule.';
    }

    public function inputSchema(): array
    {
        return $this->objectSchema([
            'family' => $this->familyProperty(false),
            'env' => $this->envProperty(),
        ], ['family']);
    }

    public function call(array $arguments, McpContext $context): ToolResult
    {
        $family = (string) $arguments['family'];

        return $this->inEnv($arguments, function () use ($family): array {
            $labels = FieldCatalog::gpioLabels($family);
            $actuators = [];
            $parameters = [];
            $lastChange = null;
            foreach ($this->repo->outputs($family) as $row) {
                $gpio = (int) $row['gpio'];
                if (in_array($gpio, self::PERSONAL_GPIOS, true)) {
                    continue;
                }
                $entry = [
                    'gpio' => $gpio,
                    'label' => $labels[$gpio] ?? (string) ($row['name'] ?? ''),
                    'name' => (string) ($row['name'] ?? ''),
                    'state' => $row['state'] === null ? null : (string) $row['state'],
                    'changed_at' => $row['requestTime'] ?? null,
                ];
                if (array_key_exists('lastModifiedBy', $row)) {
                    $entry['changed_by'] = $row['lastModifiedBy'];
                }
                if (is_string($row['requestTime'] ?? null) && ($lastChange === null || $row['requestTime'] > $lastChange['changed_at'])) {
                    $lastChange = $entry;
                }
                if ($gpio < 100) {
                    $actuators[] = $entry;
                } else {
                    $parameters[] = $entry;
                }
            }
            $board = FieldCatalog::BOARDS[$family] ?? 1;

            return [
                'family' => $family,
                'board_last_poll' => $this->repo->boardLastRequest((string) $board),
                'last_change' => $lastChange,
                'actuators' => $actuators,
                'parameters' => $parameters,
                'note' => $family === 'n3pp'
                    ? 'N3PP : GPIO 12 reflète l\'état MESURÉ de la pompe ; GPIO 15/16 ne sont pas lus par le firmware actuel.'
                    : null,
            ];
        }, $family);
    }
}

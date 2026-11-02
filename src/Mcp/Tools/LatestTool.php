<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\McpConfig;
use App\Mcp\McpContext;
use App\Mcp\Support\FieldCatalog;
use App\Mcp\ToolResult;
use App\Repository\McpReadRepository;

/**
 * `iot_latest` — dernière mesure complète d'une famille.
 */
final class LatestTool extends AbstractTool
{
    public function __construct(McpConfig $config, private McpReadRepository $repo)
    {
        parent::__construct($config);
    }

    public function name(): string
    {
        return 'iot_latest';
    }

    public function title(): string
    {
        return 'Dernière mesure d\'un module';
    }

    public function description(): string
    {
        return 'Renvoie la dernière ligne de mesures d\'une famille avec TOUS les champs du dictionnaire (unités converties : '
            . 'niveaux d\'eau FFP3 en cm), sa date, son âge en secondes et la version firmware. Les champs vides sont omis. '
            . 'Voir iot_describe pour la signification des champs.';
    }

    public function inputSchema(): array
    {
        return $this->objectSchema([
            'family' => $this->familyProperty(),
            'env' => $this->envProperty(),
        ], ['family']);
    }

    public function call(array $arguments, McpContext $context): ToolResult
    {
        $family = (string) $arguments['family'];

        return $this->inEnv($arguments, function () use ($family): array {
            $row = $this->repo->latest($family);
            if ($row === null) {
                return ['family' => $family, 'reading' => null, 'note' => 'Aucune mesure enregistrée.'];
            }
            $values = [];
            foreach (FieldCatalog::fields($family) as $field => $def) {
                $value = FieldCatalog::present($family, $field, $row[$field] ?? null);
                if ($value !== null) {
                    $values[$field] = $def['unit'] !== '' ? ['value' => $value, 'unit' => $def['unit']] : ['value' => $value];
                }
            }

            return [
                'family' => $family,
                'reading_time' => $row['reading_time'],
                'age_seconds' => self::ageSeconds((string) $row['reading_time']),
                'firmware_version' => $row['version'] ?? null,
                'values' => $values,
            ];
        }, $family);
    }
}

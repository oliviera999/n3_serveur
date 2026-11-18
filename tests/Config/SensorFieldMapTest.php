<?php

declare(strict_types=1);

namespace Tests\Config;

use App\Config\SensorFieldMap;
use App\Display\PageCatalogRegistry;
use App\Mcp\Support\FieldCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Source unique des capteurs (6.63.0) : chaque colonne citée par SensorFieldMap existe dans la
 * liste blanche MCP (FieldCatalog) avec le bon type, et les consommateurs n'ont plus de liste à eux.
 */
final class SensorFieldMapTest extends TestCase
{
    public function testPhysicalSensorsAreNumericWhitelistedFields(): void
    {
        foreach (FieldCatalog::FAMILIES as $family) {
            $physical = SensorFieldMap::physical($family);
            self::assertNotSame([], $physical, $family);
            self::assertSame([], array_diff($physical, FieldCatalog::numericFields($family)), $family);
            self::assertSame($physical, FieldCatalog::sensorFields($family), "{$family} : FieldCatalog lit la source unique");
        }
    }

    public function testEchoDiagnosticColumnsAreOptionalWhitelistedFields(): void
    {
        $columns = SensorFieldMap::echoColumns('ffp3');
        self::assertSame(['wlAquaStatus', 'wlAquaTimeouts', 'wlAquaRejected', 'wlAquaRawMm', 'wlPotaStatus', 'wlPotaTimeouts', 'wlPotaRejected', 'wlPotaRawMm'], $columns);
        foreach ($columns as $column) {
            self::assertArrayHasKey($column, FieldCatalog::fields('ffp3'), $column);
            self::assertTrue(FieldCatalog::isOptionalColumn('ffp3', $column), "{$column} : colonne de migration récente");
        }
        self::assertSame('wlPotaStatus', SensorFieldMap::echoStatusColumn('ffp3', 'EauPotager'));
        self::assertNull(SensorFieldMap::echoStatusColumn('ffp3', 'EauReserve'), 'pas de diagnostic d\'écho pour la réserve');
        self::assertSame([], SensorFieldMap::echoColumns('n3pp'));
    }

    public function testFrozenCheckedFieldsAndActivityColumnsExist(): void
    {
        self::assertSame(['EauAquarium', 'EauPotager', 'EauReserve'], SensorFieldMap::frozenCheckedFields('ffp3'));
        foreach (SensorFieldMap::frozenCheckedFields('ffp3') as $field) {
            self::assertContains($field, SensorFieldMap::physical('ffp3'));
            $activity = SensorFieldMap::frozenActivityColumn('ffp3', $field);
            if ($activity !== null) {
                self::assertContains($activity, FieldCatalog::numericFields('ffp3'), $activity);
            }
        }
        self::assertSame('etatPompeTank', SensorFieldMap::frozenActivityColumn('ffp3', 'EauReserve'));
        self::assertSame(
            ['wlAquaStatus', 'etatPompeTank', 'wlPotaStatus'],
            SensorFieldMap::auxiliaryColumns('ffp3', SensorFieldMap::physical('ffp3'))
        );
    }

    public function testWaterLevelsAreTheMmScaledFieldsAndTheFfp3CatalogCards(): void
    {
        foreach (SensorFieldMap::FFP3_WATER_LEVELS as $field) {
            self::assertSame(0.1, FieldCatalog::scale('ffp3', $field), "{$field} : mm → cm");
        }
        $catalog = (new PageCatalogRegistry())->get('ffp3');
        self::assertNotNull($catalog);
        $niveaux = $catalog->block('niveaux');
        self::assertNotNull($niveaux);
        self::assertSame(SensorFieldMap::FFP3_WATER_LEVELS, array_column($niveaux['cards'], 'key'));
    }

    public function testDisabledStatusDetection(): void
    {
        self::assertTrue(SensorFieldMap::isDisabledStatus('disabled'));
        self::assertTrue(SensorFieldMap::isDisabledStatus(' DISABLED '));
        self::assertFalse(SensorFieldMap::isDisabledStatus('ok'));
        self::assertFalse(SensorFieldMap::isDisabledStatus(null));
        self::assertSame(
            ['EauPotager' => 'wlPotaStatus'],
            SensorFieldMap::disabledInRow('ffp3', ['wlAquaStatus' => 'ok', 'wlPotaStatus' => 'disabled'])
        );
        self::assertSame([], SensorFieldMap::disabledInRow('ffp3', ['EauPotager' => 121]), 'colonne absente = rien');
    }
}

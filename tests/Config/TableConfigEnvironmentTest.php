<?php

declare(strict_types=1);

namespace Tests\Config;

use App\Config\TableConfig;
use PHPUnit\Framework\TestCase;

class TableConfigEnvironmentTest extends TestCase
{
    protected function tearDown(): void
    {
        TableConfig::resetRequestEnvironment();
        parent::tearDown();
    }

    public function testSetEnvironmentUsesRequestScopeWithoutMutatingDefault(): void
    {
        $default = TableConfig::getDefaultEnvironment();

        TableConfig::setEnvironment('test');
        $this->assertSame('test', TableConfig::getEnvironment());
        $this->assertSame('ffp3Data2', TableConfig::getDataTable());

        TableConfig::resetRequestEnvironment();
        $this->assertSame($default, TableConfig::getEnvironment());
    }

    public function testEnergieTestEnvironmentUsesTestTableAndIsTest(): void
    {
        TableConfig::setEnvironment('energie_test');
        $this->assertSame('energie_test', TableConfig::getEnvironment());
        $this->assertTrue(TableConfig::isTest());
        $this->assertSame('energieDataTest', TableConfig::getEnergieDataTable());

        // Les autres familles retombent sur leurs tables prod (pas de fuite de suffixe).
        $this->assertSame('ffp3Data', TableConfig::getDataTable());
        $this->assertSame('msp1Data', TableConfig::getMspDataTable());
        $this->assertSame('n3ppData', TableConfig::getN3ppDataTable());
    }

    public function testEnergieProdTableOutsideEnergieTest(): void
    {
        foreach (['prod', 'test', 'msp_test', 'n3pp_test', 's3'] as $env) {
            TableConfig::setEnvironment($env);
            $this->assertSame('energieData', TableConfig::getEnergieDataTable(), $env);
        }
    }

    public function testResetAfterSimulatedRequestLeak(): void
    {
        TableConfig::setEnvironment('s3');
        $this->assertSame('s3', TableConfig::getEnvironment());

        TableConfig::resetRequestEnvironment();
        $this->assertSame(TableConfig::getDefaultEnvironment(), TableConfig::getEnvironment());
    }
}

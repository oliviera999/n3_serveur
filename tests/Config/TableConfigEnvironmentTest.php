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

    public function testResetAfterSimulatedRequestLeak(): void
    {
        TableConfig::setEnvironment('s3');
        $this->assertSame('s3', TableConfig::getEnvironment());

        TableConfig::resetRequestEnvironment();
        $this->assertSame(TableConfig::getDefaultEnvironment(), TableConfig::getEnvironment());
    }
}

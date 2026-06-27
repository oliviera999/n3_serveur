<?php

declare(strict_types=1);

namespace Tests\Util;

use App\Util\TableValidator;
use PHPUnit\Framework\TestCase;

/**
 * Tests de TableValidator — whitelist des noms de tables (anti-injection).
 *
 * RÉGRESSION : les tables d'outputs MSP1 et N3PP doivent être autorisées. Sans elles,
 * NotificationPolicyRepository::ensurePolicyRows() (appelé au chargement des pages
 * /meteo-control et /serre-control) lève une InvalidArgumentException qui remonte
 * jusqu'à AbstractOutputController::showControlPage() → erreur 500.
 */
class TableValidatorTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function allowedOutputTables(): array
    {
        return [
            'ffp3 prod' => ['ffp3Outputs'],
            'ffp3 test' => ['ffp3Outputs2'],
            'ffp3 s3' => ['ffp3OutputsS3'],
            'msp1 prod' => ['msp1Outputs'],
            'msp1 test' => ['msp1OutputsTest'],
            'n3pp prod' => ['n3ppOutputs'],
            'n3pp test' => ['n3ppOutputsTest'],
        ];
    }

    /**
     * @dataProvider allowedOutputTables
     */
    public function testValidateOutputsTableAcceptsKnownTables(string $table): void
    {
        $this->assertSame($table, TableValidator::validateOutputsTable($table));
    }

    public function testValidateOutputsTableRejectsUnknownTable(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TableValidator::validateOutputsTable('evilOutputs');
    }

    public function testValidateOutputsTableRejectsInjectionAttempt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TableValidator::validateOutputsTable('ffp3Outputs; DROP TABLE ffp3Outputs');
    }
}

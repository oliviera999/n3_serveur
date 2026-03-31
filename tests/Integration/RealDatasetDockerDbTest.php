<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;

/**
 * Tests etendus lorsque la BDD Docker locale contient un snapshot de production
 * (voir tools/import-mysql-dump-to-local-docker.ps1). Sans import volumineux,
 * les cas sont marques comme skipped.
 */
class RealDatasetDockerDbTest extends IntegrationDbTestCase
{
    public function testFfp3DataVolumeWhenProductionSnapshotLoaded(): void
    {
        $pdo = $this->createPdoOrSkip();
        $this->requireProductionSnapshotOrSkip($pdo);
        $n = (int) $pdo->query('SELECT COUNT(*) FROM ffp3Data')->fetchColumn();
        self::assertGreaterThanOrEqual(self::MIN_ROWS_FFP3_SNAPSHOT, $n);
    }

    public function testBoardsKeysAreStringsCompatibleWithLocalSchema(): void
    {
        $pdo = $this->createPdoOrSkip();
        $stmt = $pdo->query('SELECT `board` FROM Boards ORDER BY `board` LIMIT 5');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            self::markTestSkipped('Table Boards vide.');
        }
        foreach ($rows as $row) {
            self::assertArrayHasKey('board', $row);
            self::assertIsString($row['board']);
            self::assertMatchesRegularExpression('/^[0-9]+$/', $row['board']);
        }
    }

    public function testMsp1DataReadableWhenSnapshotLoaded(): void
    {
        $pdo = $this->createPdoOrSkip();
        $this->requireProductionSnapshotOrSkip($pdo);
        $n = (int) $pdo->query('SELECT COUNT(*) FROM msp1Data')->fetchColumn();
        self::assertGreaterThan(100, $n, 'msp1Data devrait contenir des milliers de releves en snapshot production.');
    }
}

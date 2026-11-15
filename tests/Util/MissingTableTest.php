<?php

declare(strict_types=1);

namespace Tests\Util;

use App\Util\MissingTable;
use PDO;
use PHPUnit\Framework\TestCase;

final class MissingTableTest extends TestCase
{
    public function testMysqlMissingTableIsRecognised(): void
    {
        $e = new \PDOException("SQLSTATE[42S02]: Base table or view not found: 1146 Table 'oliviera_iot.energieData' doesn't exist");
        $this->assertTrue(MissingTable::is($e));

        $coded = new \PDOException('Table absente');
        $coded->errorInfo = ['42S02', 1146, "Table 'x' doesn't exist"];
        $this->assertTrue(MissingTable::is($coded));
    }

    public function testOtherErrorsAreNotRecognised(): void
    {
        $this->assertFalse(MissingTable::is(new \PDOException("SQLSTATE[42S22]: Column not found: 1054 Unknown column 'x'")));
        $this->assertFalse(MissingTable::is(new \PDOException('SQLSTATE[HY000] [2002] Connection refused')));
        $this->assertFalse(MissingTable::is(new \RuntimeException("Table 'x' doesn't exist")));
    }

    public function testSqliteMissingTableIsRecognised(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO sqlite driver not available');
        }
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        try {
            $pdo->query('SELECT * FROM absente');
            $this->fail('exception attendue');
        } catch (\PDOException $e) {
            $this->assertTrue(MissingTable::is($e));
        }
    }
}

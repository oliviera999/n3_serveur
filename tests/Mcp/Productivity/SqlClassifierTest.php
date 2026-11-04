<?php

declare(strict_types=1);

namespace Tests\Mcp\Productivity;

use App\Mcp\Sql\SqlStatementClassifier;
use App\Mcp\ToolException;
use PHPUnit\Framework\TestCase;

final class SqlClassifierTest extends TestCase
{
    public function testSelectIsRead(): void
    {
        $m = SqlStatementClassifier::classify('SELECT id, username FROM n3_users WHERE id = 1');
        self::assertSame(SqlStatementClassifier::CLASS_READ, $m['class']);
        self::assertFalse($m['unbounded_write']);
        self::assertSame([], $m['needs_confirm']);
    }

    public function testUpdateWithoutWhereNeedsConfirm(): void
    {
        $m = SqlStatementClassifier::classify('UPDATE ffp3Outputs2 SET state = \'1\'');
        self::assertTrue($m['unbounded_write']);
        self::assertContains('sql_unbounded_write', $m['needs_confirm']);
    }

    public function testProdOutputsWriteNeedsConfirm(): void
    {
        $m = SqlStatementClassifier::classify(
            'UPDATE ffp3Outputs SET state = \'1\' WHERE gpio = 15',
            ['ffp3Outputs', 'n3ppOutputs', 'msp1Outputs', 'ffp3OutputsS3']
        );
        self::assertTrue($m['touches_prod_outputs']);
        self::assertContains('sql_prod_outputs', $m['needs_confirm']);
    }

    public function testDdlClass(): void
    {
        $m = SqlStatementClassifier::classify('CREATE TABLE tmp_x (id INT)');
        self::assertSame(SqlStatementClassifier::CLASS_DDL, $m['class']);
        self::assertContains('sql_ddl', $m['needs_confirm']);
    }

    public function testGrantIsAccountAdmin(): void
    {
        $m = SqlStatementClassifier::classify('GRANT SELECT ON db.* TO \'u\'@\'%\'');
        self::assertSame(SqlStatementClassifier::CLASS_ACCOUNT, $m['class']);
    }

    public function testForbiddenLoadData(): void
    {
        $this->expectException(ToolException::class);
        SqlStatementClassifier::classify('LOAD DATA INFILE \'/tmp/x\' INTO TABLE t');
    }

    public function testForbiddenOutfile(): void
    {
        $this->expectException(ToolException::class);
        SqlStatementClassifier::classify('SELECT * FROM t INTO OUTFILE \'/tmp/x\'');
    }

    public function testForbiddenShutdown(): void
    {
        $this->expectException(ToolException::class);
        SqlStatementClassifier::classify('SHUTDOWN');
    }

    public function testForbiddenSecretColumn(): void
    {
        $this->expectException(ToolException::class);
        SqlStatementClassifier::classify('SELECT password_hash FROM n3_users');
    }

    public function testForbiddenProtectedTableWrite(): void
    {
        $this->expectException(ToolException::class);
        SqlStatementClassifier::classify('DELETE FROM mcp_confirmations WHERE 1=1');
    }

    public function testMultiStatementRejected(): void
    {
        $this->expectException(ToolException::class);
        SqlStatementClassifier::classify('SELECT 1; DROP TABLE n3_users');
    }

    public function testCommentsStripped(): void
    {
        $m = SqlStatementClassifier::classify("SELECT 1 /* comment */ -- fin\n");
        self::assertSame(SqlStatementClassifier::CLASS_READ, $m['class']);
    }

    public function testUpdateModifierStillDetectsProductionTable(): void
    {
        $prod = ['ffp3Outputs', 'n3ppOutputs', 'msp1Outputs', 'ffp3OutputsS3'];
        $m = SqlStatementClassifier::classify(
            'UPDATE LOW_PRIORITY IGNORE ffp3Outputs SET state = \'0\' WHERE gpio = 16',
            $prod
        );
        self::assertTrue($m['touches_prod_outputs']);
        self::assertContains('sql_prod_outputs', $m['needs_confirm']);
        self::assertContains('ffp3Outputs', $m['tables']);
    }

    public function testSchemaQualifiedProductionTableNeedsConfirm(): void
    {
        $m = SqlStatementClassifier::classify(
            'UPDATE `olution`.`ffp3Outputs` SET state = \'0\' WHERE gpio = 16',
            ['ffp3Outputs']
        );
        self::assertTrue($m['touches_prod_outputs']);
        self::assertContains('sql_prod_outputs', $m['needs_confirm']);
        self::assertNotContains('olution', $m['tables']);
    }

    public function testSecondTableInUpdateListIsSeen(): void
    {
        $m = SqlStatementClassifier::classify(
            'UPDATE dummy, ffp3Outputs SET ffp3Outputs.state = \'0\' WHERE dummy.id = 1',
            ['ffp3Outputs']
        );
        self::assertContains('ffp3Outputs', $m['tables']);
        self::assertTrue($m['touches_prod_outputs']);
    }

    public function testSchemaQualifiedProtectedTableIsRejected(): void
    {
        $this->expectException(ToolException::class);
        SqlStatementClassifier::classify('DELETE FROM olution.mcp_oauth_tokens WHERE id = 1');
    }

    public function testWhereOnlyInsideStringIsUnbounded(): void
    {
        $m = SqlStatementClassifier::classify("UPDATE ffp3Data SET note = 'pas de WHERE ici'");
        self::assertTrue($m['unbounded_write']);
        self::assertContains('sql_unbounded_write', $m['needs_confirm']);
    }

    public function testRealWhereStillCounts(): void
    {
        $m = SqlStatementClassifier::classify("UPDATE ffp3Data SET note = 'WHERE' WHERE id = 4");
        self::assertTrue($m['has_where']);
        self::assertFalse($m['unbounded_write']);
        self::assertSame([], $m['needs_confirm']);
    }
}

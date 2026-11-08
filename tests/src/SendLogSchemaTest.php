<?php

namespace Tests\ConectaEnte;

use ConectaEnte\Database\SendLogSchema;
use PHPUnit\Framework\TestCase;

class SendLogSchemaTest extends TestCase
{
    public function testLogTableHasAUniqueRequestUuidAndNoOpportunityForeignKey()
    {
        $sql = $this->flat(SendLogSchema::createLogTable());

        $this->assertStringContainsString('request_uuid VARCHAR(36) NOT NULL', $sql);
        $this->assertStringContainsString('UNIQUE (request_uuid)', $sql);
        $this->assertStringContainsString('opportunity_id INT NOT NULL', $sql);
        $this->assertStringNotContainsString('REFERENCES opportunity', $sql);
        $this->assertStringContainsString('FOREIGN KEY (user_id) REFERENCES usr (id) ON DELETE SET NULL', $sql);
    }

    public function testAttemptsDieWithTheirLog()
    {
        $this->assertStringContainsString('FOREIGN KEY (log_id) REFERENCES conectaente_send_log (id) ON DELETE CASCADE', $this->flat(SendLogSchema::createAttemptTable()));
    }

    public function testAttemptKeepsTheResponseButNoRequestHeaders()
    {
        $sql = $this->flat(SendLogSchema::createAttemptTable());

        foreach (['payload JSONB', 'response JSONB', 'response_headers JSONB', 'http_status INT', 'duration_ms INT', 'par_edital_id', 'request_id'] as $column) {
            $this->assertStringContainsString($column, $sql);
        }

        $this->assertStringNotContainsString('request_headers', $sql);
    }

    public function testParEditalIdAndRequestIdAreIndexed()
    {
        $indexes = implode(' ', SendLogSchema::createAttemptIndexes());

        $this->assertStringContainsString('(par_edital_id)', $indexes);
        $this->assertStringContainsString('(request_id)', $indexes);
    }

    public function testParEditalOwnerIndexIsUniqueAndPartial()
    {
        $this->assertSame(
            "CREATE UNIQUE INDEX unq_conectaente_par_edital_id ON opportunity_meta (value) WHERE key = 'conectaente_parEditalId' AND value <> ''",
            SendLogSchema::createParEditalOwnerIndex('conectaente_parEditalId')
        );
    }

    // metadado limpo pela API vira '': duas oportunidades podem tê-lo vazio sem violar a unicidade
    public function testEmptyValuesAreOutOfTheOwnerIndexAndTheCleanup()
    {
        $this->assertStringContainsString("AND value <> ''", SendLogSchema::createParEditalOwnerIndex('k'));
        $this->assertStringContainsString("AND value <> \\'\\'", file_get_contents(dirname(__DIR__, 2) . '/Migrations/ClaimParEditalId.php'));
    }

    public function testMigrationsArePrefixedAndCleanBeforeTheUniqueIndex()
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/db-updates.php');

        $this->assertStringContainsString("'conectaente: create send_log tables'", $source);
        $this->assertStringContainsString("'conectaente: one opportunity per par_edital_id'", $source);
        $this->assertLessThan(strpos($source, 'createParEditalOwnerIndex'), strpos($source, 'ClaimParEditalId::run()'));
    }

    private function flat($sql)
    {
        return preg_replace('/\s+/', ' ', $sql);
    }
}

<?php

namespace Tests\ConectaEnte;

use ConectaEnte\Http\Exchange;
use ConectaEnte\Services\SendLogService;
use ConectaEnte\Services\SendReport;
use ConectaEnte\Vocabulary\SendOutcome;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Sem banco: confere o SQL e os parâmetros que o serviço manda executar.
 */
class SendLogServiceTest extends TestCase
{
    const UUID = '11111111-2222-4333-8444-555555555555';

    private $connection;
    private $statements;
    private $logged;
    private $service;
    private $affected = 1;

    protected function setUp(): void
    {
        $this->statements = [];
        $this->logged = [];
        $this->connection = $this->createMock(Connection::class);
        $this->connection->method('executeUpdate')->willReturnCallback(function ($sql, $params) {
            $this->statements[] = [preg_replace('/\s+/', ' ', $sql), $params];

            return $this->affected;
        });
        $this->service = new SendLogService($this->connection, function ($message) {
            $this->logged[] = $message;
        });
    }

    public function testNewUuidIsAVersion4Uuid()
    {
        $this->assertRegExp('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', SendLogService::newUuid());
        $this->assertNotSame(SendLogService::newUuid(), SendLogService::newUuid());
    }

    public function testStartingAbandonsOtherPendingAndNeverTouchesItsOwn()
    {
        $this->service->startOrResume(self::UUID, 7, 3);

        $this->assertCount(2, $this->statements);
        list($abandon, $params) = $this->statements[1];
        $this->assertStringContainsString("SET status = :abandoned", $abandon);
        $this->assertStringContainsString('status = :pending AND request_uuid <> :except', $abandon);
        $this->assertSame(self::UUID, $params['except']);
        $this->assertSame('abandoned', $params['abandoned']);

        list($insert, $insertParams) = $this->statements[0];
        $this->assertStringContainsString('ON CONFLICT (request_uuid) DO NOTHING', $insert);
        $this->assertSame('pending', $insertParams['status']);
        $this->assertSame(3, $insertParams['user']);
    }

    public function testResumingAnExistingSendDoesNotAbandonTheNewerOnes()
    {
        $this->affected = 0;

        $this->service->startOrResume(self::UUID, 7);

        $this->assertCount(1, $this->statements);
        $this->assertStringContainsString('INSERT INTO conectaente_send_log', $this->statements[0][0]);
    }

    public function testADeletedUserDoesNotBlockOpeningTheSend()
    {
        $this->service->startOrResume(self::UUID, 7, 99);

        $this->assertStringContainsString('(SELECT id FROM usr WHERE id = CAST(:user AS integer))', $this->statements[0][0]);
    }

    public function testCorrelationValuesAreCutToTheColumnSize()
    {
        $exchange = new Exchange('https://h/x', 'POST', ['id' => 7], 200, '{"a":1}');
        $report = new SendReport(SendOutcome::SUCCESS, null, str_repeat('9', 100), str_repeat('r', 300), $exchange);

        $this->service->recordAttempt(self::UUID, 1, 3, $report);

        $params = $this->statements[0][1];
        $this->assertSame(64, strlen($params['par_edital_id']));
        $this->assertSame(255, strlen($params['request_id']));
        $this->assertSame('{"a":1}', $params['response']);
    }

    public function testAbandoningIsASingleConditionalUpdate()
    {
        $this->service->abandonPending(7);

        $this->assertCount(1, $this->statements);
        $this->assertStringStartsWith('UPDATE conectaente_send_log SET status', $this->statements[0][0]);
    }

    public function testFinishOnlyLeavesPending()
    {
        $this->service->finish(self::UUID, SendOutcome::SUCCESS);

        list($sql, $params) = $this->statements[0];
        $this->assertStringContainsString('WHERE request_uuid = :uuid AND status = :pending', $sql);
        $this->assertSame('success', $params['status']);
        $this->assertSame('pending', $params['pending']);
    }

    /**
     * @dataProvider invalidOutcomes
     */
    public function testMissingOutcomeIsStoredAsErrorNeverSuccess($outcome)
    {
        $this->service->finish(self::UUID, $outcome);

        $this->assertSame('error', $this->statements[0][1]['status']);
    }

    public function invalidOutcomes()
    {
        return [[null], [''], ['pending'], ['whatever']];
    }

    public function testRecordsTheAttemptWithEverythingButTheRequestHeaders()
    {
        $exchange = new Exchange('https://h/api/v1/oportunidades/7', 'PUT', ['id' => 7], 200, '{"id_par_edital":9}', ['X-Request-Id' => 'req-1'], 193);
        $report = new SendReport(SendOutcome::SUCCESS, null, '9', 'req-1', $exchange);

        $this->service->recordAttempt(self::UUID, 1, 3, $report);

        list($sql, $params) = $this->statements[0];
        $this->assertStringContainsString('INSERT INTO conectaente_send_log_attempt', $sql);
        $this->assertStringContainsString('WHERE l.request_uuid = :uuid', $sql);
        $this->assertSame(200, $params['http_status']);
        $this->assertSame('PUT', $params['method']);
        $this->assertSame('{"id":7}', $params['payload']);
        $this->assertSame('{"id_par_edital":9}', $params['response']);
        $this->assertSame('{"x-request-id":"req-1"}', $params['headers']);
        $this->assertSame('9', $params['par_edital_id']);
        $this->assertSame('req-1', $params['request_id']);
        $this->assertSame(193, $params['duration']);
        $this->assertSame('success', $params['status']);
        $this->assertNull($params['error']);
        $this->assertStringNotContainsStringIgnoringCase('token', json_encode($params));
    }

    public function testRetryScheduledIsRecordedAsAFailedAttempt()
    {
        $exchange = new Exchange('https://h/x', 'PUT', [], null, '', [], 30, 'timeout');
        $report = new SendReport(SendOutcome::PENDING, 'timeout', null, null, $exchange);

        $this->service->recordAttempt(self::UUID, 1, 3, $report);

        $params = $this->statements[0][1];
        $this->assertSame('error', $params['status']);
        $this->assertSame('timeout', $params['error']);
        $this->assertNull($params['http_status']);
    }

    public function testRejectionKeepsItsOwnStatus()
    {
        $exchange = new Exchange('https://h/x', 'PUT', [], 422, '{"detail":"x"}');
        $this->service->recordAttempt(self::UUID, 1, 3, new SendReport(SendOutcome::REJECTED, 'x', null, null, $exchange));

        $this->assertSame('rejected', $this->statements[0][1]['status']);
    }

    public function testSimulatedAttemptKeepsThePayload()
    {
        $exchange = Exchange::simulated('https://h/x', 'PUT', ['id' => 7]);
        $this->service->recordAttempt(self::UUID, 1, 3, new SendReport(SendOutcome::SIMULATED, null, null, null, $exchange));

        $params = $this->statements[0][1];
        $this->assertSame('simulated', $params['status']);
        $this->assertSame('{"id":7}', $params['payload']);
    }

    public function testAnAbandonedReportRecordsNoAttempt()
    {
        $this->service->recordAttempt(self::UUID, 1, 3, new SendReport(SendOutcome::ABANDONED, 'inelegível'));

        $this->assertSame([], $this->statements);
    }

    public function testFailureWritingTheHistoryIsSwallowedAndLogged()
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeUpdate')->willThrowException(new \RuntimeException('connection lost'));
        $service = new SendLogService($connection, function ($message) {
            $this->logged[] = $message;
        });

        $this->assertFalse($service->startOrResume(self::UUID, 7));
        $this->assertFalse($service->finish(self::UUID, SendOutcome::SUCCESS));
        $this->assertFalse($service->recordAttempt(self::UUID, 1, 3, new SendReport(SendOutcome::ERROR, 'x')));
        // abrir o envio falha no INSERT e não segue para abandonar os pendentes
        $this->assertCount(3, $this->logged);
        $this->assertStringContainsString('connection lost', $this->logged[0]);
    }
}

<?php

namespace Tests\ConectaEnte;

use ConectaEnte\Entities\SendLog;
use ConectaEnte\Entities\SendLogAttempt;
use ConectaEnte\Repositories\SendLogRepository;
use ConectaEnte\Services\SendHistoryService;
use PHPUnit\Framework\TestCase;

/**
 * O que o endpoint do histórico devolve: permissão, paginação e a lista fixa de campos.
 */
class SendHistoryServiceTest extends TestCase
{
    private $repository;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(SendLogRepository::class);
    }

    public function testWithoutPermissionNothingIsRead()
    {
        $this->repository->expects($this->never())->method('findPage');

        $result = $this->service(false)->page(7, 1);

        $this->assertSame(403, $result->getStatus());
        $this->assertTrue($result->isError());
    }

    public function testThePermissionIsAskedAboutTheRequestedOpportunity()
    {
        $asked = null;
        $this->repository->method('findPage')->willReturn([]);

        (new SendHistoryService($this->repository, function ($id) use (&$asked) {
            $asked = $id;

            return true;
        }))->page('7', 1);

        $this->assertSame(7, $asked);
    }

    public function testAskingForTheFirstPageReadsTwentyPlusOne()
    {
        $this->repository->expects($this->once())->method('findPage')->with(7, 0, 21)->willReturn([]);

        $this->service(true)->page(7, 1);
    }

    public function testLaterPagesSkipThePreviousOnes()
    {
        $this->repository->expects($this->once())->method('findPage')->with(7, 40, 21)->willReturn([]);

        $this->service(true)->page(7, 3);
    }

    /**
     * @dataProvider invalidPages
     */
    public function testInvalidPageFallsBackToTheFirst($page)
    {
        $this->repository->expects($this->once())->method('findPage')->with(7, 0, 21)->willReturn([]);

        $this->assertSame(1, $this->service(true)->page(7, $page)->getBody()['page']);
    }

    public function invalidPages()
    {
        return [[0], [-3], ['abc'], [null]];
    }

    public function testTheTwentyFirstLogOnlySaysThereIsMore()
    {
        $logs = [];
        for ($id = 1; $id <= 21; $id++) {
            $logs[] = $this->log($id, 'success');
        }
        $this->repository->method('findPage')->willReturn($logs);
        $this->repository->method('findAttempts')->willReturn([]);

        $body = $this->service(true)->page(7, 1)->getBody();

        $this->assertCount(20, $body['items']);
        $this->assertTrue($body['hasMore']);
        $this->assertSame(20, $body['items'][19]['id']);
    }

    public function testExactlyOnePageHasNoMore()
    {
        $logs = [$this->log(1, 'success'), $this->log(2, 'error')];
        $this->repository->method('findPage')->willReturn($logs);
        $this->repository->method('findAttempts')->willReturn([]);

        $body = $this->service(true)->page(7, 1)->getBody();

        $this->assertCount(2, $body['items']);
        $this->assertFalse($body['hasMore']);
    }

    public function testEachLogCarriesItsAttemptsAndLabels()
    {
        $log = $this->log(5, 'abandoned');
        $this->repository->method('findPage')->willReturn([$log]);
        $this->repository->method('findAttempts')->willReturn([5 => [$this->attempt(1, 'error', 503), $this->attempt(2, 'success', 200)]]);

        $item = $this->service(true)->page(7, 1)->getBody()['items'][0];

        $this->assertSame('Descartado', $item['statusLabel']);
        $this->assertSame('11111111-2222-4333-8444-555555555555', $item['requestUuid']);
        $this->assertSame(3, $item['userId']);
        $this->assertCount(2, $item['attempts']);
        $this->assertSame('Falhou', $item['attempts'][0]['statusLabel']);
        $this->assertSame(503, $item['attempts'][0]['httpStatus']);
        $this->assertSame('Enviado', $item['attempts'][1]['statusLabel']);
        $this->assertSame(['x-request-id' => 'req-1'], $item['attempts'][1]['responseHeaders']);
    }

    public function testAHugePageIsCappedInsteadOfOverflowingTheOffset()
    {
        $this->repository->expects($this->once())->method('findPage')
            ->with(7, (SendHistoryService::MAX_PAGE - 1) * SendHistoryService::PAGE_SIZE, 21)->willReturn([]);

        $body = $this->service(true)->page(7, '99999999999999999999')->getBody();

        $this->assertSame(SendHistoryService::MAX_PAGE, $body['page']);
        $this->assertSame([], $body['items']);
    }

    public function testCredentialHeadersNeverLeaveInTheResponse()
    {
        $this->repository->method('findPage')->willReturn([$this->log(5, 'success')]);
        $this->repository->method('findAttempts')->willReturn([5 => [
            $this->attempt(1, 'success', 200, ['id_par_edital' => 9], ['x-request-id' => 'req-1', 'set-cookie' => 's=1', 'authorization' => 'Bearer x']),
            $this->attempt(2, 'error', 401, null, ['set-cookie' => 's=1']),
        ]]);

        $attempts = $this->service(true)->page(7, 1)->getBody()['items'][0]['attempts'];

        $this->assertSame(['x-request-id' => 'req-1'], $attempts[0]['responseHeaders']);
        $this->assertNull($attempts[1]['responseHeaders']);
    }

    public function testALogWithoutAttemptsHasAnEmptyList()
    {
        $this->repository->method('findPage')->willReturn([$this->log(5, 'pending')]);
        $this->repository->method('findAttempts')->willReturn([]);

        $this->assertSame([], $this->service(true)->page(7, 1)->getBody()['items'][0]['attempts']);
    }

    // lista fixa: coluna nova na entidade só chega à tela se alguém a incluir aqui
    public function testOnlyTheExpectedFieldsLeave()
    {
        $this->repository->method('findPage')->willReturn([$this->log(5, 'success')]);
        $this->repository->method('findAttempts')->willReturn([5 => [$this->attempt(1, 'success', 200)]]);

        $item = $this->service(true)->page(7, 1)->getBody()['items'][0];

        $this->assertSame(['id', 'requestUuid', 'status', 'statusLabel', 'createdAt', 'userId', 'attempts'], array_keys($item));
        $this->assertSame(
            ['attempt', 'maxAttempts', 'status', 'statusLabel', 'sentAt', 'endpoint', 'method', 'httpStatus', 'durationMs', 'requestId', 'parEditalId', 'errorMessage', 'payload', 'responseHeaders', 'response'],
            array_keys($item['attempts'][0])
        );
        $this->assertStringNotContainsStringIgnoringCase('token', json_encode($item));
    }

    public function testTheRawEnvelopeIsKeptAsStored()
    {
        $this->repository->method('findPage')->willReturn([$this->log(5, 'error')]);
        $this->repository->method('findAttempts')->willReturn([5 => [$this->attempt(1, 'error', 500, ['raw' => 'Internal Server Error'])]]);

        $attempt = $this->service(true)->page(7, 1)->getBody()['items'][0]['attempts'][0];

        $this->assertSame(['raw' => 'Internal Server Error'], $attempt['response']);
    }

    public function testAnUnknownStatusIsShownAsStored()
    {
        $this->repository->method('findPage')->willReturn([$this->log(5, 'novo')]);
        $this->repository->method('findAttempts')->willReturn([]);

        $this->assertSame('novo', $this->service(true)->page(7, 1)->getBody()['items'][0]['statusLabel']);
    }

    private function service($allowed)
    {
        return new SendHistoryService($this->repository, function () use ($allowed) {
            return $allowed;
        });
    }

    private function log($id, $status)
    {
        $log = $this->createMock(SendLog::class);
        $log->method('getId')->willReturn($id);
        $log->method('getRequestUuid')->willReturn('11111111-2222-4333-8444-555555555555');
        $log->method('getResult')->willReturn($status);
        $log->method('getCreateTimestamp')->willReturn(new \DateTime('2026-11-08 20:39:19'));
        $log->method('getUserId')->willReturn(3);

        return $log;
    }

    private function attempt($number, $status, $http, $response = ['id_par_edital' => 9], $headers = ['x-request-id' => 'req-1'])
    {
        $attempt = $this->createMock(SendLogAttempt::class);
        $attempt->method('getAttempt')->willReturn($number);
        $attempt->method('getMaxAttempts')->willReturn(3);
        $attempt->method('getResult')->willReturn($status);
        $attempt->method('getSentAt')->willReturn(new \DateTime('2026-11-08 20:39:20'));
        $attempt->method('getEndpoint')->willReturn('https://h/api/v1/oportunidades/');
        $attempt->method('getHttpMethod')->willReturn('POST');
        $attempt->method('getHttpStatus')->willReturn($http);
        $attempt->method('getDurationMs')->willReturn(193);
        $attempt->method('getRequestId')->willReturn('req-1');
        $attempt->method('getParEditalId')->willReturn('9');
        $attempt->method('getErrorMessage')->willReturn(null);
        $attempt->method('getPayload')->willReturn(['id' => 7]);
        $attempt->method('getResponseHeaders')->willReturn($headers);
        $attempt->method('getResponse')->willReturn($response);

        return $attempt;
    }
}

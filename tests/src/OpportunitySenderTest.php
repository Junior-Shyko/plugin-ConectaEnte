<?php

namespace Tests\ConectaEnte;

use ConectaEnte\Entities\FederativeEntity;
use ConectaEnte\Http\Client;
use ConectaEnte\Payload\OpportunityPayload;
use ConectaEnte\Repositories\FederativeEntityRepository;
use ConectaEnte\Services\EligibilityResult;
use ConectaEnte\Services\OpportunitySender;
use ConectaEnte\Services\SendEligibility;
use ConectaEnte\Vocabulary\SendOutcome;
use MapasCulturais\Entities\Opportunity;
use PHPUnit\Framework\TestCase;
use Tests\ConectaEnte\Doubles\FakeOpportunity;
use Tests\ConectaEnte\Doubles\FakeTransport;

/**
 * O sender decide o desfecho de um envio: reelegibilidade, modo dev, e a classificação da resposta do CultBR
 * pela RetryPolicy. O transporte é fixture, então nada sai da máquina.
 */
class OpportunitySenderTest extends TestCase
{
    const HOST = 'https://ente.conecta.hmg.cultbr.cultura.gov.br';
    const MAX_ATTEMPTS = 3;

    private $entity;
    private $repository;
    private $payloadBuilds;

    protected function setUp(): void
    {
        $this->entity = $this->createMock(FederativeEntity::class);
        $this->entity->method('getId')->willReturn(5);
        $this->entity->method('getToken')->willReturn('token-do-ente');

        $this->repository = $this->createMock(FederativeEntityRepository::class);
        $this->repository->method('findBySealId')->willReturnMap([[10, $this->entity]]);

        $this->payloadBuilds = 0;
    }

    public function testAcceptedSendIsSuccessAndKeepsTheCorrelationIds()
    {
        $transport = FakeTransport::replying(200, ['id_par_edital' => 123], ['x-request-id' => 'req-1']);

        $report = $this->send($transport, true, 1);

        $this->assertSame(SendOutcome::SUCCESS, $report->getOutcome());
        $this->assertSame('123', $report->getParEditalId());
        $this->assertSame('req-1', $report->getRequestId());
        $this->assertNull($report->getMessage());
    }

    public function testAcceptedSendWithoutParEditalIdKeepsItNull()
    {
        $report = $this->send(FakeTransport::replying(200, ['status' => 'recebido']), true, 1);

        $this->assertSame(SendOutcome::SUCCESS, $report->getOutcome());
        $this->assertNull($report->getParEditalId());
        $this->assertNull($report->getRequestId());
    }

    public function testSendsTheBuiltPayloadWithTheEntityTokenToTheOpportunityUrl()
    {
        $transport = FakeTransport::replying(200, ['id_par_edital' => 1]);

        $this->send($transport, true, 1);

        $this->assertSame(['PUT'], $transport->sentVerbs);
        $this->assertSame(['id' => 7], $transport->sentBodies[0]);
        $this->assertSame(['token' => 'token-do-ente'], $transport->sentHeaders[0]);
        $this->assertSame(self::HOST . '/api/v1/oportunidades/7', $transport->requestedUrls[0]);
    }

    /**
     * 5xx e falha de conexão retentam até o limite; no limite viram erro.
     */
    public function testServerErrorRetriesWhileThereAreAttemptsLeft()
    {
        $report = $this->send(FakeTransport::replying(500, 'erro interno', []), true, 1);

        $this->assertSame(SendOutcome::PENDING, $report->getOutcome());
        $this->assertNull($report->getParEditalId());
    }

    public function testServerErrorOnTheLastAttemptIsError()
    {
        $report = $this->send(FakeTransport::replying(503, 'indisponível', []), true, self::MAX_ATTEMPTS);

        $this->assertSame(SendOutcome::ERROR, $report->getOutcome());
    }

    public function testNonJsonServerErrorReportsTheStatusAsTheReason()
    {
        $report = $this->send(FakeTransport::replying(500, 'erro interno', []), true, 1);

        $this->assertSame('HTTP 500', $report->getMessage());
    }

    public function testConnectionFailureRetriesAndReportsTheTransportError()
    {
        $report = $this->send(FakeTransport::unreachable(), true, 1);

        $this->assertSame(SendOutcome::PENDING, $report->getOutcome());
        $this->assertSame('Could not resolve host', $report->getMessage());
    }

    public function testConnectionFailureOnTheLastAttemptIsError()
    {
        $report = $this->send(FakeTransport::unreachable(), true, self::MAX_ATTEMPTS);

        $this->assertSame(SendOutcome::ERROR, $report->getOutcome());
    }

    public function testOkWithoutJsonBodyIsNotAnAcceptance()
    {
        $report = $this->send(FakeTransport::replying(200, '<html>proxy</html>', []), true, 1);

        $this->assertSame(SendOutcome::PENDING, $report->getOutcome());
    }

    /**
     * @dataProvider definitiveStatuses
     */
    public function testClientErrorIsRejectedWithoutRetryEvenOnTheFirstAttempt($status)
    {
        $transport = FakeTransport::replying($status, ['detail' => 'motivo da recusa']);

        $report = $this->send($transport, true, 1);

        $this->assertSame(SendOutcome::REJECTED, $report->getOutcome());
        $this->assertSame('motivo da recusa', $report->getMessage());
        $this->assertCount(1, $transport->sentVerbs);
    }

    public function definitiveStatuses()
    {
        return [[400], [401], [403], [422]];
    }

    public function testUnprocessableEntityKeepsEveryFieldErrorOfTheDetailList()
    {
        $detail = [
            ['loc' => ['body', 'numero_previsto_vagas'], 'msg' => 'campo obrigatório'],
            ['loc' => ['body', 'data_inicio'], 'msg' => 'data inválida'],
        ];

        $report = $this->send(FakeTransport::replying(422, ['detail' => $detail]), true, 1);

        $this->assertSame(SendOutcome::REJECTED, $report->getOutcome());
        $this->assertStringContainsString('campo obrigatório', explode('; ', $report->getMessage())[0]);
        $this->assertStringContainsString('data inválida', explode('; ', $report->getMessage())[1]);
    }

    public function testRejectionWithoutDetailFallsBackToTheStatus()
    {
        $report = $this->send(FakeTransport::replying(403, 'proibido', []), true, 1);

        $this->assertSame(SendOutcome::REJECTED, $report->getOutcome());
        $this->assertStringContainsString('403', $report->getMessage());
    }

    public function testDevModeIsSimulatedAndNeverReachesTheCultBr()
    {
        $transport = FakeTransport::replying(200, ['id_par_edital' => 1]);

        $report = $this->send($transport, false, 1);

        $this->assertSame(SendOutcome::SIMULATED, $report->getOutcome());
        $this->assertSame([], $transport->sentVerbs);
        $this->assertNull($report->getParEditalId());
    }

    public function testDevModeStillBuildsThePayload()
    {
        $this->send(FakeTransport::replying(200, []), false, 1);

        $this->assertSame(1, $this->payloadBuilds);
    }

    public function testNoLongerEligibleIsAbandonedWithoutSending()
    {
        $transport = FakeTransport::replying(200, ['id_par_edital' => 1]);
        $draft = new FakeOpportunity(Opportunity::STATUS_DRAFT, [$this->relation(10)]);

        $report = $this->sender($transport, true)->send($draft, $this->entity, 1, self::MAX_ATTEMPTS);

        $this->assertSame(SendOutcome::ABANDONED, $report->getOutcome());
        $this->assertSame(EligibilityResult::NOT_PUBLISHED, $report->getMessage());
        $this->assertSame([], $transport->sentVerbs);
    }

    public function testMissingFieldsAbandonTheSend()
    {
        $transport = FakeTransport::replying(200, []);

        $report = $this->sender($transport, true, ['numberVacancies'])->send($this->published(), $this->entity, 1, self::MAX_ATTEMPTS);

        $this->assertSame(SendOutcome::ABANDONED, $report->getOutcome());
        $this->assertSame(EligibilityResult::MISSING_FIELDS, $report->getMessage());
        $this->assertSame([], $transport->sentVerbs);
    }

    public function testSealOfAnotherEntityAbandonsTheSend()
    {
        $transport = FakeTransport::replying(200, []);
        $other = $this->createMock(FederativeEntity::class);
        $other->method('getId')->willReturn(99);

        $report = $this->sender($transport, true)->send($this->published(), $other, 1, self::MAX_ATTEMPTS);

        $this->assertSame(SendOutcome::ABANDONED, $report->getOutcome());
        $this->assertSame([], $transport->sentVerbs);
    }

    private function send(FakeTransport $transport, $live, $attempt)
    {
        return $this->sender($transport, $live)->send($this->published(), $this->entity, $attempt, self::MAX_ATTEMPTS);
    }

    private function sender(FakeTransport $transport, $live, array $missing = [])
    {
        $test = $this;

        // o build real precisa da entidade do core; aqui só importa que o sender o chame e envie o resultado
        $payload = new class($test) extends OpportunityPayload {
            private $test;

            public function __construct($test)
            {
                $this->test = $test;
            }

            public function build($opportunity, $entity)
            {
                $this->test->payloadBuilt();

                return ['id' => 7];
            }
        };

        $eligibility = new SendEligibility($this->repository, function () use ($missing) {
            return $missing;
        });

        return new OpportunitySender(new Client(self::HOST, $transport), $payload, $eligibility, $live);
    }

    public function payloadBuilt()
    {
        $this->payloadBuilds++;
    }

    private function published()
    {
        return new FakeOpportunity(Opportunity::STATUS_ENABLED, [$this->relation(10)]);
    }

    private function relation($sealId)
    {
        return (object) ['seal' => (object) ['id' => $sealId]];
    }
}

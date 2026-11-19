<?php

namespace Tests\ConectaEnte;

use ConectaEnte\Entities\FederativeEntity;
use ConectaEnte\Entities\SendLog;
use ConectaEnte\Entities\SendLogAttempt;
use ConectaEnte\Repositories\SendLogRepository;
use ConectaEnte\Services\EligibilityResult;
use ConectaEnte\Services\SendEligibility;
use ConectaEnte\Services\SendStatusService;
use PHPUnit\Framework\TestCase;
use Tests\ConectaEnte\Doubles\FakeOpportunity;

/**
 * A situação que a oportunidade mostra de si: elegibilidade de agora, último envio e o que nunca sai na resposta.
 */
class SendStatusServiceTest extends TestCase
{
    private $eligibility;
    private $repository;

    protected function setUp(): void
    {
        $this->eligibility = $this->createMock(SendEligibility::class);
        $this->repository = $this->createMock(SendLogRepository::class);
        $this->repository->method('findPage')->willReturn([]);
        $this->repository->method('findAttempts')->willReturn([]);
    }

    public function testWithoutPermissionNothingIsChecked()
    {
        $this->eligibility->expects($this->never())->method('check');
        $this->repository->expects($this->never())->method('findPage');

        $result = $this->service(false)->status(new FakeOpportunity());

        $this->assertSame(403, $result->getStatus());
        $this->assertTrue($result->isError());
    }

    public function testThePermissionIsAskedAboutTheOpportunity()
    {
        $asked = null;
        $this->eligibility->method('check')->willReturn(EligibilityResult::ineligible(EligibilityResult::NO_SEAL));

        (new SendStatusService($this->eligibility, $this->repository, function ($id) use (&$asked) {
            $asked = $id;

            return true;
        }, [$this, 'messages']))->status(new FakeOpportunity());

        $this->assertSame(7, $asked);
    }

    /**
     * @dataProvider withoutEntity
     */
    public function testWithoutAnEntityNothingAppearsAndNoLogIsRead($reason)
    {
        $this->eligibility->method('check')->willReturn(EligibilityResult::ineligible($reason));
        $repository = $this->createMock(SendLogRepository::class);
        $repository->expects($this->never())->method('findPage');

        $body = (new SendStatusService($this->eligibility, $repository, function () {
            return true;
        }, [$this, 'messages']))->status(new FakeOpportunity())->getBody();

        $this->assertSame(['visible' => false, 'state' => 'hidden'], $body);
    }

    public function withoutEntity()
    {
        return [[EligibilityResult::NO_SEAL], [EligibilityResult::SEAL_WITHOUT_ENTITY]];
    }

    public function testIneligibleIsNotSentAndNamesTheMissingFields()
    {
        $this->eligibility->method('check')->willReturn(EligibilityResult::ineligible(EligibilityResult::MISSING_FIELDS, ['shortDescription', 'nomeDoContrato']));

        $body = $this->service(true)->status(new FakeOpportunity())->getBody();

        $this->assertTrue($body['visible']);
        $this->assertSame('ineligible', $body['state']);
        $this->assertSame('Não enviada', $body['label']);
        $this->assertSame(['O campo «Descrição curta» é obrigatório.', 'nomeDoContrato'], $body['missing']);
        $this->assertNull($body['lastSend']);
    }

    /**
     * @dataProvider reasonsWithoutFields
     */
    public function testOtherReasonsAreIneligibleWithoutFields($reason)
    {
        $this->eligibility->method('check')->willReturn(EligibilityResult::ineligible($reason));

        $body = $this->service(true)->status(new FakeOpportunity())->getBody();

        $this->assertSame('ineligible', $body['state']);
        $this->assertNotEmpty($body['message']);
        $this->assertSame([], $body['missing']);
    }

    public function reasonsWithoutFields()
    {
        return [[EligibilityResult::IS_PHASE], [EligibilityResult::ARCHIVED_OR_TRASH], [EligibilityResult::NOT_PUBLISHED]];
    }

    public function testIneligibleStaysTheMainStateEvenWithAFailedLastSend()
    {
        $this->eligibility->method('check')->willReturn(EligibilityResult::ineligible(EligibilityResult::NOT_PUBLISHED));
        $repository = $this->repositoryWith('error');

        $body = $this->serviceWith($repository)->status(new FakeOpportunity())->getBody();

        $this->assertSame('ineligible', $body['state']);
        $this->assertSame('error', $body['lastSend']['status']);
        $this->assertSame('Falhou', $body['lastSend']['statusLabel']);
    }

    public function testEligibleWithoutAnySendIsNotSentYet()
    {
        $this->eligibility->method('check')->willReturn(EligibilityResult::eligible($this->createMock(FederativeEntity::class)));

        $body = $this->service(true)->status(new FakeOpportunity())->getBody();

        $this->assertSame('not_sent', $body['state']);
        $this->assertSame('Ainda não enviada', $body['label']);
        $this->assertNull($body['lastSend']);
    }

    /**
     * @dataProvider outcomes
     */
    public function testEligibleShowsTheOutcomeOfTheLastSend($status, $label)
    {
        $this->eligibility->method('check')->willReturn(EligibilityResult::eligible($this->createMock(FederativeEntity::class)));

        $body = $this->serviceWith($this->repositoryWith($status))->status(new FakeOpportunity())->getBody();

        $this->assertSame($status, $body['state']);
        $this->assertSame($label, $body['label']);
        $this->assertSame($label, $body['lastSend']['statusLabel']);
    }

    public function outcomes()
    {
        return [
            ['success', 'Enviado'],
            ['error', 'Falhou'],
            ['rejected', 'Recusado'],
            ['pending', 'Em andamento'],
            ['simulated', 'Simulado'],
            ['abandoned', 'Descartado'],
        ];
    }

    public function testAnErrorDoesNotClaimThatTheAttemptsRanOut()
    {
        $this->eligibility->method('check')->willReturn(EligibilityResult::eligible($this->createMock(FederativeEntity::class)));
        $repository = $this->repositoryWith('error', [$this->attempt(null, 'Erro local')]);

        $body = $this->serviceWith($repository)->status(new FakeOpportunity())->getBody();

        $this->assertSame('O envio falhou.', $body['message']);
        $this->assertSame('Erro local', $body['lastSend']['errorMessage']);
    }

    public function testADiscardedSendMentionsBothWaysItCanHappen()
    {
        $this->eligibility->method('check')->willReturn(EligibilityResult::eligible($this->createMock(FederativeEntity::class)));

        $body = $this->serviceWith($this->repositoryWith('abandoned'))->status(new FakeOpportunity())->getBody();

        $this->assertSame(
            'O envio foi descartado: foi substituído por um envio mais novo, ou a oportunidade ou o ente mudou antes de ele terminar.',
            $body['message']
        );
    }

    public function testSimulatedNeverReadsAsSuccess()
    {
        $this->eligibility->method('check')->willReturn(EligibilityResult::eligible($this->createMock(FederativeEntity::class)));

        $simulated = $this->serviceWith($this->repositoryWith('simulated'))->status(new FakeOpportunity())->getBody();
        $success = $this->serviceWith($this->repositoryWith('success'))->status(new FakeOpportunity())->getBody();

        $this->assertNotSame($success['label'], $simulated['label']);
        $this->assertNotSame($success['message'], $simulated['message']);
        $this->assertStringContainsString('não recebeu', $simulated['message']);
    }

    public function testAFailureCarriesTheReasonFromTheLastAttempt()
    {
        $this->eligibility->method('check')->willReturn(EligibilityResult::eligible($this->createMock(FederativeEntity::class)));
        $repository = $this->repositoryWith('rejected', [$this->attempt(422, 'Campo inválido'), $this->attempt(400, 'Edital recusado')]);

        $last = $this->serviceWith($repository)->status(new FakeOpportunity())->getBody()['lastSend'];

        $this->assertSame(400, $last['httpStatus']);
        $this->assertSame('Edital recusado', $last['errorMessage']);
    }

    public function testASendWithoutAttemptsStillHasTheOutcome()
    {
        $this->eligibility->method('check')->willReturn(EligibilityResult::eligible($this->createMock(FederativeEntity::class)));

        $last = $this->serviceWith($this->repositoryWith('abandoned'))->status(new FakeOpportunity())->getBody()['lastSend'];

        $this->assertSame('abandoned', $last['status']);
        $this->assertNull($last['httpStatus']);
        $this->assertNull($last['errorMessage']);
    }

    public function testOnlyTheLatestSendIsRead()
    {
        $this->eligibility->method('check')->willReturn(EligibilityResult::eligible($this->createMock(FederativeEntity::class)));
        $repository = $this->createMock(SendLogRepository::class);
        $repository->expects($this->once())->method('findPage')->with(7, 0, 1)->willReturn([]);

        $this->serviceWith($repository)->status(new FakeOpportunity());
    }

    // lista fixa: nada além do necessário chega à tela, e nunca payload, resposta, cabeçalhos ou token
    public function testOnlyTheExpectedFieldsLeave()
    {
        $this->eligibility->method('check')->willReturn(EligibilityResult::eligible($this->createMock(FederativeEntity::class)));
        $repository = $this->repositoryWith('success', [$this->attempt(200, null)]);

        $body = $this->serviceWith($repository)->status(new FakeOpportunity())->getBody();

        $this->assertSame(['visible', 'state', 'label', 'message', 'missing', 'lastSend'], array_keys($body));
        $this->assertSame(['status', 'statusLabel', 'at', 'httpStatus', 'errorMessage', 'parEditalId'], array_keys($body['lastSend']));
        $this->assertStringNotContainsStringIgnoringCase('token', json_encode($body));
        $this->assertStringNotContainsStringIgnoringCase('authorization', json_encode($body));
    }

    public function messages()
    {
        return ['shortDescription' => ['O campo «Descrição curta» é obrigatório.']];
    }

    private function service($allowed)
    {
        return new SendStatusService($this->eligibility, $this->repository, function () use ($allowed) {
            return $allowed;
        }, [$this, 'messages']);
    }

    private function serviceWith(SendLogRepository $repository)
    {
        return new SendStatusService($this->eligibility, $repository, function () {
            return true;
        }, [$this, 'messages']);
    }

    private function repositoryWith($status, array $attempts = [])
    {
        $log = $this->createMock(SendLog::class);
        $log->method('getId')->willReturn(5);
        $log->method('getResult')->willReturn($status);
        $log->method('getCreateTimestamp')->willReturn(new \DateTime('2026-11-08 20:39:19'));

        $repository = $this->createMock(SendLogRepository::class);
        $repository->method('findPage')->willReturn([$log]);
        $repository->method('findAttempts')->willReturn($attempts === [] ? [] : [5 => $attempts]);

        return $repository;
    }

    private function attempt($http, $error)
    {
        $attempt = $this->createMock(SendLogAttempt::class);
        $attempt->method('getHttpStatus')->willReturn($http);
        $attempt->method('getErrorMessage')->willReturn($error);
        $attempt->method('getParEditalId')->willReturn('9');
        $attempt->method('getPayload')->willReturn(['token' => 'segredo']);
        $attempt->method('getResponseHeaders')->willReturn(['authorization' => 'Bearer x']);

        return $attempt;
    }
}

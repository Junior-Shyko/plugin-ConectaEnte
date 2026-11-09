<?php

namespace Tests\ConectaEnte;

use ConectaEnte\Http\Client;
use PHPUnit\Framework\TestCase;
use Tests\ConectaEnte\Doubles\FakeTransport;

class ClientSendOpportunityTest extends TestCase
{
    const HOST = 'https://ente.conecta.hmg.cultbr.cultura.gov.br';

    public function testAcceptedSendReturnsTheDecodedResponse()
    {
        $transport = FakeTransport::replying(200, ['id_oportunidades_mapa' => 42, 'status' => 'recebido']);

        $result = (new Client(self::HOST, $transport))->sendOpportunity('um-token', ['id' => 7]);

        $this->assertTrue($result->isAccepted());
        $this->assertSame(42, $result->getResponse()['id_oportunidades_mapa']);
    }

    public function testAcceptedSendKeepsTheRequestIdHeader()
    {
        $transport = FakeTransport::replying(200, ['id_oportunidades_mapa' => 42], ['x-request-id' => 'req-123']);

        $result = (new Client(self::HOST, $transport))->sendOpportunity('um-token', ['id' => 7]);

        $this->assertSame('req-123', $result->getRequestId());
    }

    public function testRequestIdHeaderLookupIsCaseInsensitive()
    {
        $transport = FakeTransport::replying(200, ['id_oportunidades_mapa' => 42], ['X-Request-Id' => 'req-456']);

        $result = (new Client(self::HOST, $transport))->sendOpportunity('um-token', ['id' => 7]);

        $this->assertSame('req-456', $result->getRequestId());
    }

    public function testStatus200WithoutJsonIsUnreachableAndRetryable()
    {
        $transport = FakeTransport::replying(200, '<html>proxy</html>', []);

        $result = (new Client(self::HOST, $transport))->sendOpportunity('um-token', ['id' => 7]);

        $this->assertFalse($result->isAccepted());
        $this->assertTrue($result->isUnreachable());
        $this->assertSame(\ConectaEnte\Vocabulary\SendOutcome::PENDING, \ConectaEnte\Services\RetryPolicy::outcome($result, 1, 3));
    }

    public function testRejectionKeepsTheHttpStatusForTheRetryPolicy()
    {
        $transport = FakeTransport::replying(422, ['detail' => 'numero_previsto_vagas: campo obrigatório']);

        $result = (new Client(self::HOST, $transport))->sendOpportunity('um-token', ['id' => 7]);

        $this->assertFalse($result->isAccepted());
        $this->assertSame(422, $result->getStatus());
    }

    public function testServerErrorIsUnreachableWithItsStatus()
    {
        $transport = FakeTransport::replying(500, 'erro interno', []);

        $result = (new Client(self::HOST, $transport))->sendOpportunity('um-token', ['id' => 7]);

        $this->assertTrue($result->isUnreachable());
        $this->assertSame(500, $result->getStatus());
    }

    public function testSendsTheBodyAsJsonWithTheTokenHeader()
    {
        $transport = FakeTransport::replying(200, ['id_oportunidades_mapa' => 1]);

        (new Client(self::HOST, $transport))->sendOpportunity('um-token', ['id' => 7, 'numero_e_titulo_edital' => 'Edital 1']);

        $this->assertSame(['id' => 7, 'numero_e_titulo_edital' => 'Edital 1'], $transport->sentBodies[0]);
        $this->assertSame(['token' => 'um-token'], $transport->sentHeaders[0]);
    }

    // o POST na coleção cria ou atualiza; o id do Mapas vai no corpo
    public function testTheSendGoesAsAPostToTheCollection()
    {
        $transport = FakeTransport::replying(200, ['id_oportunidades_mapa' => 1]);

        (new Client(self::HOST, $transport))->sendOpportunity('um-token', ['id' => 7]);

        $this->assertSame('POST', $transport->sentVerbs[0]);
        $this->assertSame(self::HOST . '/api/v1/oportunidades/', $transport->requestedUrls[0]);
    }

    public function testClientErrorsAreRejectedNotUnreachable()
    {
        foreach ([400, 401, 403, 422] as $status) {
            $transport = FakeTransport::replying($status, ['detail' => 'motivo']);

            $result = (new Client(self::HOST, $transport))->sendOpportunity('um-token', []);

            $this->assertFalse($result->isAccepted());
            $this->assertFalse($result->isUnreachable(), "status {$status} não pode virar unreachable");
            $this->assertSame('motivo', $result->getMessage());
        }
    }

    public function testReadsFieldErrorsFromValidationResponse()
    {
        $transport = FakeTransport::replying(422, ['detail' => [
            ['loc' => ['body', 'ente_federado'], 'msg' => 'Field required', 'type' => 'missing'],
        ]]);

        $result = (new Client(self::HOST, $transport))->sendOpportunity('um-token', []);

        $this->assertSame('ente_federado: Field required', $result->getMessage());
    }

    public function testEveryRejectedFieldIsNamedInOneLine()
    {
        $transport = FakeTransport::replying(422, ['detail' => [
            ['loc' => ['body', 'ente_federado'], 'msg' => 'Field required', 'type' => 'missing'],
            ['loc' => ['body', 'forma_de_execucao'], 'msg' => 'Input should be a valid string', 'type' => 'string_type'],
        ]]);

        $result = (new Client(self::HOST, $transport))->sendOpportunity('um-token', []);

        $this->assertSame(
            'ente_federado: Field required; forma_de_execucao: Input should be a valid string',
            $result->getMessage()
        );
    }

    public function testNestedFieldKeepsThePathWithoutTheOrigin()
    {
        $transport = FakeTransport::replying(422, ['detail' => [
            ['loc' => ['body', 'fontes_de_recurso', 0, 'valor'], 'msg' => 'Input should be a valid number', 'type' => 'float_parsing'],
        ]]);

        $result = (new Client(self::HOST, $transport))->sendOpportunity('um-token', []);

        $this->assertSame('fontes_de_recurso.0.valor: Input should be a valid number', $result->getMessage());
    }

    public function testFieldErrorWithoutLocationFallsBackToTheMessageAlone()
    {
        $transport = FakeTransport::replying(422, ['detail' => [['msg' => 'Erro sem campo', 'type' => 'value_error']]]);

        $result = (new Client(self::HOST, $transport))->sendOpportunity('um-token', []);

        $this->assertSame('Erro sem campo', $result->getMessage());
    }

    // o CultBR pode devolver `detail` fora do formato declarado, e isso não pode virar erro de tipo
    public function testMalformedDetailItemsFallBackInsteadOfBreaking()
    {
        $esperado = 'A Plataforma CultBR recusou o envio do edital (HTTP 422).';

        foreach ([['texto solto'], [null], [42], [[]]] as $detail) {
            $transport = FakeTransport::replying(422, ['detail' => $detail]);

            $result = (new Client(self::HOST, $transport))->sendOpportunity('um-token', []);

            $this->assertFalse($result->isAccepted());
            $this->assertSame($esperado, $result->getMessage());
        }
    }

    public function testHeaderErrorsAreNotNamedAsEditalFields()
    {
        $transport = FakeTransport::replying(422, ['detail' => [
            ['loc' => ['header', 'token'], 'msg' => 'Field required', 'type' => 'missing'],
        ]]);

        $result = (new Client(self::HOST, $transport))->sendOpportunity('um-token', []);

        $this->assertSame('Field required', $result->getMessage());
    }

    public function testClientErrorWithoutUsableBodyKeepsTheStatusAndSaysItWasTheSend()
    {
        $transport = FakeTransport::replying(400, 'Bad Request');

        $result = (new Client(self::HOST, $transport))->sendOpportunity('um-token', []);

        $this->assertFalse($result->isUnreachable());
        $this->assertStringContainsString('400', $result->getMessage());
        $this->assertStringContainsString('envio', $result->getMessage());
        $this->assertStringNotContainsString('verificação', $result->getMessage());
    }

    // status checado antes de parsear JSON: 500 com corpo text/plain não vira "resposta inválida"
    public function testServerErrorWithPlainTextBodyIsUnreachableNotAParseFailure()
    {
        $transport = FakeTransport::replying(500, 'Internal Server Error');

        $result = (new Client(self::HOST, $transport))->sendOpportunity('um-token', []);

        $this->assertTrue($result->isUnreachable());
        $this->assertSame(500, $result->getStatus());
        $this->assertFalse($result->isAccepted());
    }

    public function testTransportFailureIsUnreachable()
    {
        $result = (new Client(self::HOST, FakeTransport::unreachable()))->sendOpportunity('um-token', []);

        $this->assertTrue($result->isUnreachable());
    }

    public function testKeepsWhatWasExchangedForTheHistory()
    {
        $transport = FakeTransport::replying(200, ['id_par_edital' => 9], ['x-request-id' => 'req-1'], 193);

        $exchange = (new Client(self::HOST, $transport))->sendOpportunity('um-token', ['id' => 7])->getExchange();

        $this->assertSame(self::HOST . '/api/v1/oportunidades/', $exchange->getEndpoint());
        $this->assertSame('POST', $exchange->getMethod());
        $this->assertSame(['id' => 7], $exchange->getPayload());
        $this->assertSame(200, $exchange->getStatus());
        $this->assertSame(['x-request-id' => 'req-1'], $exchange->getHeaders());
        $this->assertSame(193, $exchange->getDurationMs());
        $this->assertSame('{"id_par_edital":9}', $exchange->getBody());
    }

    public function testExchangeNeverCarriesTheToken()
    {
        $transport = FakeTransport::replying(200, ['id_par_edital' => 9]);

        $exchange = (new Client(self::HOST, $transport))->sendOpportunity('um-token-secreto', ['id' => 7])->getExchange();

        $this->assertStringNotContainsString('um-token-secreto', serialize($exchange));
    }

    public function testExchangeOfAnUnreachableServerHasNoStatus()
    {
        $exchange = (new Client(self::HOST, FakeTransport::unreachable()))->sendOpportunity('um-token', ['id' => 7])->getExchange();

        $this->assertNull($exchange->getStatus());
        $this->assertSame('Could not resolve host', $exchange->getTransportError());
    }
}

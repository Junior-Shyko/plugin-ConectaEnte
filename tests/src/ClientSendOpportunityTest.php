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

        $result = (new Client(self::HOST, $transport))->sendOpportunity('um-token', 7, ['id' => 7]);

        $this->assertTrue($result->isAccepted());
        $this->assertSame(42, $result->getResponse()['id_oportunidades_mapa']);
    }

    public function testSendsTheBodyAsJsonWithTheTokenHeader()
    {
        $transport = FakeTransport::replying(200, ['id_oportunidades_mapa' => 1]);

        (new Client(self::HOST, $transport))->sendOpportunity('um-token', 7, ['id' => 7, 'numero_e_titulo_edital' => 'Edital 1']);

        $this->assertSame(['id' => 7, 'numero_e_titulo_edital' => 'Edital 1'], $transport->sentBodies[0]);
        $this->assertSame(['token' => 'um-token'], $transport->sentHeaders[0]);
    }

    // o contrato só atualiza via PUT no recurso, identificado pelo id na própria URL
    public function testTheSendGoesAsAPutToTheResource()
    {
        $transport = FakeTransport::replying(200, ['id_oportunidades_mapa' => 1]);

        (new Client(self::HOST, $transport))->sendOpportunity('um-token', 7, ['id' => 7]);

        $this->assertSame('PUT', $transport->sentVerbs[0]);
        $this->assertSame(self::HOST . '/api/v1/oportunidades/7', $transport->requestedUrls[0]);
    }

    public function testClientErrorsAreRejectedNotUnreachable()
    {
        foreach ([400, 401, 403, 422] as $status) {
            $transport = FakeTransport::replying($status, ['detail' => 'motivo']);

            $result = (new Client(self::HOST, $transport))->sendOpportunity('um-token', 7, []);

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

        $result = (new Client(self::HOST, $transport))->sendOpportunity('um-token', 7, []);

        $this->assertSame('ente_federado: Field required', $result->getMessage());
    }

    public function testEveryRejectedFieldIsNamedInOneLine()
    {
        $transport = FakeTransport::replying(422, ['detail' => [
            ['loc' => ['body', 'ente_federado'], 'msg' => 'Field required', 'type' => 'missing'],
            ['loc' => ['body', 'forma_de_execucao'], 'msg' => 'Input should be a valid string', 'type' => 'string_type'],
        ]]);

        $result = (new Client(self::HOST, $transport))->sendOpportunity('um-token', 7, []);

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

        $result = (new Client(self::HOST, $transport))->sendOpportunity('um-token', 7, []);

        $this->assertSame('fontes_de_recurso.0.valor: Input should be a valid number', $result->getMessage());
    }

    public function testFieldErrorWithoutLocationFallsBackToTheMessageAlone()
    {
        $transport = FakeTransport::replying(422, ['detail' => [['msg' => 'Erro sem campo', 'type' => 'value_error']]]);

        $result = (new Client(self::HOST, $transport))->sendOpportunity('um-token', 7, []);

        $this->assertSame('Erro sem campo', $result->getMessage());
    }

    // o CultBR pode devolver `detail` fora do formato declarado, e isso não pode virar erro de tipo
    public function testMalformedDetailItemsFallBackInsteadOfBreaking()
    {
        $esperado = 'A Plataforma CultBR recusou o envio do edital (HTTP 422).';

        foreach ([['texto solto'], [null], [42], [[]]] as $detail) {
            $transport = FakeTransport::replying(422, ['detail' => $detail]);

            $result = (new Client(self::HOST, $transport))->sendOpportunity('um-token', 7, []);

            $this->assertFalse($result->isAccepted());
            $this->assertSame($esperado, $result->getMessage());
        }
    }

    public function testHeaderErrorsAreNotNamedAsEditalFields()
    {
        $transport = FakeTransport::replying(422, ['detail' => [
            ['loc' => ['header', 'token'], 'msg' => 'Field required', 'type' => 'missing'],
        ]]);

        $result = (new Client(self::HOST, $transport))->sendOpportunity('um-token', 7, []);

        $this->assertSame('Field required', $result->getMessage());
    }

    public function testClientErrorWithoutUsableBodyKeepsTheStatusAndSaysItWasTheSend()
    {
        $transport = FakeTransport::replying(400, 'Bad Request');

        $result = (new Client(self::HOST, $transport))->sendOpportunity('um-token', 7, []);

        $this->assertFalse($result->isUnreachable());
        $this->assertStringContainsString('400', $result->getMessage());
        $this->assertStringContainsString('envio', $result->getMessage());
        $this->assertStringNotContainsString('verificação', $result->getMessage());
    }

    // status checado antes de parsear JSON: 500 com corpo text/plain não vira "resposta inválida"
    public function testServerErrorWithPlainTextBodyIsUnreachableNotAParseFailure()
    {
        $transport = FakeTransport::replying(500, 'Internal Server Error');

        $result = (new Client(self::HOST, $transport))->sendOpportunity('um-token', 7, []);

        $this->assertTrue($result->isUnreachable());
        $this->assertSame(500, $result->getStatus());
        $this->assertFalse($result->isAccepted());
    }

    public function testTransportFailureIsUnreachable()
    {
        $result = (new Client(self::HOST, FakeTransport::unreachable()))->sendOpportunity('um-token', 7, []);

        $this->assertTrue($result->isUnreachable());
    }
}

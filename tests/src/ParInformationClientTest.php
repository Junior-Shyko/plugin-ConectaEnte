<?php

namespace Tests\ConectaEnte;

use ConectaEnte\Http\Client;
use ConectaEnte\Http\ParInformationResult;
use ConectaEnte\Http\Response;
use ConectaEnte\Http\Transport\FixtureTransport;
use PHPUnit\Framework\TestCase;
use Tests\ConectaEnte\Doubles\FakeTransport;
use Tests\ConectaEnte\Doubles\SequencedTransport;

class ParInformationClientTest extends TestCase
{
    const HOST = 'https://ente.conecta.hmg.cultbr.cultura.gov.br';
    const CNPJ = '12200176000176';

    private function entity($cnpj, $exerciseId)
    {
        return [
            'nome' => 'Ente ' . $cnpj,
            'cnpj' => $cnpj,
            'exercicios' => [['id' => $exerciseId, 'ano' => 2025, 'metas' => []]],
        ];
    }

    private function page(array $data, $next = null, $skip = 0)
    {
        return SequencedTransport::page([
            'pagination' => ['skip' => $skip, 'limit' => Client::PAR_PAGE_SIZE, 'total' => 0, 'next' => $next, 'previous' => null],
            'data' => $data,
        ]);
    }

    public function testPicksTheEntityOfTheCnpjAndReportsTheOthers()
    {
        $transport = new SequencedTransport([$this->page([
            $this->entity('99999999000191', 1),
            $this->entity(self::CNPJ, 2),
            $this->entity('88888888000188', 3),
        ])]);

        $result = (new Client(self::HOST, $transport))->getParInformation('um-token', self::CNPJ);

        $this->assertSame(ParInformationResult::OK, $result->getState());
        $this->assertSame(self::CNPJ, $result->getTree()->cnpj);
        $this->assertSame('2', $result->getTree()->exercises[0]->id);
        $this->assertSame(['99999999000191', '88888888000188'], $result->getOtherCnpjs());
    }

    public function testSendsTheTokenInTheHeaderAndPagesInTheQuery()
    {
        $transport = new SequencedTransport([$this->page([$this->entity(self::CNPJ, 2)])]);

        (new Client(self::HOST, $transport))->getParInformation('um-token', self::CNPJ);

        $this->assertSame(['token' => 'um-token'], $transport->sentHeaders[0]);
        $this->assertSame(self::HOST . '/api/v1/par-information?skip=0&limit=' . Client::PAR_PAGE_SIZE, $transport->requestedUrls[0]);
    }

    public function testFollowsThePagesUntilTheCnpjIsFound()
    {
        $transport = new SequencedTransport([
            $this->page([$this->entity('99999999000191', 1)], 'proxima', 0),
            $this->page([$this->entity(self::CNPJ, 2)], null, Client::PAR_PAGE_SIZE),
        ]);

        $result = (new Client(self::HOST, $transport))->getParInformation('um-token', self::CNPJ);

        $this->assertSame(ParInformationResult::OK, $result->getState());
        $this->assertCount(2, $transport->requestedUrls);
        $this->assertStringContainsString('skip=' . Client::PAR_PAGE_SIZE, $transport->requestedUrls[1]);
    }

    public function testACnpjWithoutTreeIsNotFoundNotAnEmptyTree()
    {
        $transport = new SequencedTransport([$this->page([$this->entity('99999999000191', 1)])]);

        $result = (new Client(self::HOST, $transport))->getParInformation('um-token', self::CNPJ);

        $this->assertSame(ParInformationResult::NOT_FOUND, $result->getState());
        $this->assertNull($result->getTree());
        $this->assertTrue($result->isDefinitive());
    }

    public function testAnUnreachableApiIsUnavailableAndNotDefinitive()
    {
        $result = (new Client(self::HOST, FakeTransport::unreachable()))->getParInformation('um-token', self::CNPJ);

        $this->assertSame(ParInformationResult::UNAVAILABLE, $result->getState());
        $this->assertFalse($result->isDefinitive());
    }

    public function testAServerErrorIsUnavailableWithTheApiDetail()
    {
        $transport = FakeTransport::replying(500, ['detail' => 'banco fora do ar']);

        $result = (new Client(self::HOST, $transport))->getParInformation('um-token', self::CNPJ);

        $this->assertSame(ParInformationResult::UNAVAILABLE, $result->getState());
        $this->assertSame('banco fora do ar', $result->getMessage());
    }

    // um corpo sem `data` não pode virar árvore vazia: o job gravaria "sem PAR" no cache
    public function testAResponseWithoutDataIsUnavailableNotNotFound()
    {
        $transport = FakeTransport::replying(200, ['pagina' => 'de proxy']);

        $result = (new Client(self::HOST, $transport))->getParInformation('um-token', self::CNPJ);

        $this->assertSame(ParInformationResult::UNAVAILABLE, $result->getState());
    }

    public function testANonJsonBodyIsUnavailable()
    {
        $transport = new SequencedTransport([Response::received(200, '<html>proxy</html>')]);

        $result = (new Client(self::HOST, $transport))->getParInformation('um-token', self::CNPJ);

        $this->assertSame(ParInformationResult::UNAVAILABLE, $result->getState());
    }

    public function testAnEndlessPaginationStopsAsIncompleteAndIsUnavailable()
    {
        $responses = [];
        for ($i = 0; $i < Client::PAR_MAX_PAGES; $i++) {
            $responses[] = $this->page([$this->entity('99999999000191', $i + 1)], 'proxima');
        }

        $result = (new Client(self::HOST, new SequencedTransport($responses)))->getParInformation('um-token', self::CNPJ);

        $this->assertSame(ParInformationResult::UNAVAILABLE, $result->getState());
        $this->assertStringContainsString('páginas', $result->getMessage());
    }

    // o modo simulado lê o fixture, que tem a forma real da API (pagination + data)
    public function testTheSimulatedFixtureMatchesTheApiShapeAndTheFixtureEntity()
    {
        $fixtures = dirname(__DIR__, 2) . '/fixtures';

        $result = (new Client(self::HOST, new FixtureTransport($fixtures)))->getParInformation('qualquer', '00000000000191');

        $this->assertSame(ParInformationResult::OK, $result->getState());
        $this->assertTrue($result->isSimulated());
        $this->assertNotEmpty($result->getTree()->exercises);
    }
}

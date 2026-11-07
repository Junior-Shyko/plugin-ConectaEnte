<?php

namespace Tests\ConectaEnte;

use ConectaEnte\Entities\FederativeEntity;
use ConectaEnte\Http\Client;
use ConectaEnte\Http\ParInformationResult;
use ConectaEnte\Services\ParInformationService;
use PHPUnit\Framework\TestCase;
use Tests\ConectaEnte\Doubles\ArrayCache;
use Tests\ConectaEnte\Doubles\SequencedTransport;

class ParInformationServiceTest extends TestCase
{
    const CNPJ = '12200176000176';

    private $cache;
    private $logged = [];

    protected function setUp(): void
    {
        $this->cache = new ArrayCache();
    }

    private function entity($id = 7)
    {
        $entity = (new \ReflectionClass(FederativeEntity::class))->newInstanceWithoutConstructor();
        $ref = new \ReflectionProperty(FederativeEntity::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($entity, $id);
        $entity->setToken('um-token-bem-longo');
        $entity->setCnpj(self::CNPJ);

        return $entity;
    }

    private function service(array $responses)
    {
        $transport = new SequencedTransport($responses);
        $service = new ParInformationService(new Client('https://ente.test', $transport), $this->cache, 3600, 'dev|https://ente.test', function ($message) {
            $this->logged[] = $message;
        });

        return [$service, $transport];
    }

    private function treeFor($cnpj)
    {
        return SequencedTransport::page(['pagination' => ['next' => null], 'data' => [[
            'nome' => 'Ente', 'cnpj' => $cnpj, 'exercicios' => [['id' => 1, 'ano' => 2025, 'metas' => []]],
        ]]]);
    }

    // a tela nunca chama a API: sem cache, responde pending
    public function testReadingWithoutCacheIsPendingAndCallsNothing()
    {
        [$service, $transport] = $this->service([]);

        $result = $service->cached($this->entity());

        $this->assertSame(ParInformationResult::PENDING, $result->getState());
        $this->assertSame([], $transport->requestedUrls);
    }

    public function testAFetchedTreeIsCachedAndReadBackWithoutCallingTheApi()
    {
        [$service, $transport] = $this->service([$this->treeFor(self::CNPJ)]);
        $entity = $this->entity();

        $service->fetch($entity);
        $read = $service->cached($entity);

        $this->assertSame(ParInformationResult::OK, $read->getState());
        $this->assertCount(1, $transport->requestedUrls);
    }

    // a falha não vira árvore nem "sem PAR": a tela mostra que está indisponível, e a próxima busca tenta de novo
    public function testAFailedFetchIsShownAsUnavailableAndIsNotDefinitive()
    {
        [$service] = $this->service([SequencedTransport::page(['x' => 1], 503)]);
        $entity = $this->entity();

        $result = $service->fetch($entity);

        $this->assertSame(ParInformationResult::UNAVAILABLE, $result->getState());
        $this->assertSame(ParInformationResult::UNAVAILABLE, $service->cached($entity)->getState());
        $this->assertCount(1, $this->cache->saved);
        $this->assertNotEmpty($this->logged);
    }

    public function testATreeThatArrivesAfterAFailureReplacesIt()
    {
        [$service] = $this->service([SequencedTransport::page(['x' => 1], 503), $this->treeFor(self::CNPJ)]);
        $entity = $this->entity();

        $service->fetch($entity);
        $service->fetch($entity);

        $this->assertSame(ParInformationResult::OK, $service->cached($entity)->getState());
    }

    // trocar o token muda a chave: a árvore do token antigo não é lida, mesmo que ainda esteja no cache
    public function testATreeFromAnOldTokenIsNotReadAfterTheTokenChanges()
    {
        [$service] = $this->service([$this->treeFor(self::CNPJ)]);
        $entity = $this->entity();
        $service->fetch($entity);

        $entity->setToken('outro-token-bem-longo');

        $this->assertSame(ParInformationResult::PENDING, $service->cached($entity)->getState());
    }

    public function testEnvironmentsDoNotShareTheSameEntry()
    {
        [$dev] = $this->service([]);
        $transport = new SequencedTransport([]);
        $live = new ParInformationService(new Client('https://ente.test', $transport), $this->cache, 3600, 'live|https://ente.test');
        $entity = $this->entity();

        $this->assertNotSame($dev->cacheKey($entity), $live->cacheKey($entity));
    }

    public function testAFailedWriteToTheCacheIsLogged()
    {
        [$service] = $this->service([$this->treeFor(self::CNPJ)]);
        $this->cache->failSaves = true;

        $service->fetch($this->entity());

        $this->assertCount(1, $this->logged);
        $this->assertStringContainsString('não pôde ser gravada', $this->logged[0]);
    }

    public function testTheOtherCnpjsOfANotFoundResponseAreLogged()
    {
        $body = SequencedTransport::page(['pagination' => ['next' => null], 'data' => [
            ['cnpj' => '99999999000191', 'exercicios' => []],
        ]]);
        [$service] = $this->service([$body]);

        $result = $service->fetch($this->entity());

        $this->assertSame(ParInformationResult::NOT_FOUND, $result->getState());
        $this->assertCount(1, $this->logged);
        $this->assertStringContainsString('99999999000191', $this->logged[0]);
    }

    public function testAnotherCnpjsInTheResponseAreLogged()
    {
        $body = SequencedTransport::page(['pagination' => ['next' => null], 'data' => [
            ['cnpj' => '99999999000191', 'exercicios' => []],
            ['cnpj' => self::CNPJ, 'exercicios' => []],
        ]]);
        [$service] = $this->service([$body]);

        $service->fetch($this->entity());

        $this->assertCount(1, $this->logged);
        $this->assertStringContainsString('99999999000191', $this->logged[0]);
    }

    public function testForgetDropsTheCachedTree()
    {
        [$service] = $this->service([$this->treeFor(self::CNPJ)]);
        $entity = $this->entity();
        $service->fetch($entity);

        $service->forget($entity);

        $this->assertSame(ParInformationResult::PENDING, $service->cached($entity)->getState());
        $key = $service->cacheKey($entity);
        $this->assertSame([$key, $key . ParInformationService::FAILURE_SUFFIX], array_slice($this->cache->deleted, -2));
    }

    public function testTheCacheKeyIsPerEntity()
    {
        [$service] = $this->service([]);

        $this->assertStringStartsWith(ParInformationService::CACHE_PREFIX, $service->cacheKey($this->entity(7)));
        $this->assertNotSame($service->cacheKey($this->entity(7)), $service->cacheKey($this->entity(8)));
    }
}

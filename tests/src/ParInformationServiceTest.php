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
        $service = new ParInformationService(new Client('https://ente.test', $transport), $this->cache, 3600, function ($message) {
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

    public function testAnUnavailableFetchIsNotCachedAndIsLogged()
    {
        [$service] = $this->service([SequencedTransport::page(['x' => 1], 503)]);
        $entity = $this->entity();

        $result = $service->fetch($entity);

        $this->assertSame(ParInformationResult::UNAVAILABLE, $result->getState());
        $this->assertSame([], $this->cache->saved);
        $this->assertSame(ParInformationResult::PENDING, $service->cached($entity)->getState());
        $this->assertNotEmpty($this->logged);
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
        $this->assertSame([ParInformationService::CACHE_PREFIX . '7'], $this->cache->deleted);
    }

    public function testTheCacheKeyIsPerEntity()
    {
        $this->assertSame('conectaente:par-information:federative-entity:7', ParInformationService::cacheKey($this->entity(7)));
        $this->assertNotSame(ParInformationService::cacheKey($this->entity(7)), ParInformationService::cacheKey($this->entity(8)));
    }
}

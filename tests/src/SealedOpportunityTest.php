<?php

namespace Tests\ConectaEnte;

use ConectaEnte\Entities\FederativeEntity;
use ConectaEnte\Repositories\FederativeEntityRepository;
use ConectaEnte\Services\SealedOpportunity;
use PHPUnit\Framework\TestCase;

class SealedOpportunityTest extends TestCase
{
    private $repository;
    private $service;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(FederativeEntityRepository::class);
        $this->service = new SealedOpportunity($this->repository);
    }

    public function testOpportunityWithTheSealOfAnEntityIsSealedAndReturnsThatEntity()
    {
        $entity = $this->createMock(FederativeEntity::class);
        $this->repository->method('findBySealId')->willReturnMap([[10, $entity]]);

        $opportunity = $this->opportunity([10]);

        $this->assertTrue($this->service->isSealed($opportunity));
        $this->assertSame($entity, $this->service->entityFor($opportunity));
    }

    public function testOpportunityWithOnlyCommonSealsIsNotSealed()
    {
        $this->repository->method('findBySealId')->willReturn(null);

        $opportunity = $this->opportunity([10, 11]);

        $this->assertFalse($this->service->isSealed($opportunity));
        $this->assertNull($this->service->entityFor($opportunity));
    }

    public function testOpportunityWithoutSealsIsNotSealed()
    {
        $this->repository->expects($this->never())->method('findBySealId');

        $this->assertFalse($this->service->isSealed($this->opportunity([])));
    }

    public function testTheEntitySealIsFoundAmongCommonOnes()
    {
        $entity = $this->createMock(FederativeEntity::class);
        $this->repository->method('findBySealId')->willReturnMap([[10, null], [11, $entity]]);

        $this->assertSame($entity, $this->service->entityFor($this->opportunity([10, 11])));
    }

    private function opportunity(array $sealIds)
    {
        $relations = array_map(function ($id) {
            return (object) ['seal' => (object) ['id' => $id]];
        }, $sealIds);

        $opportunity = $this->getMockBuilder(\stdClass::class)->setMethods(['getSealRelations'])->getMock();
        $opportunity->method('getSealRelations')->willReturn($relations);

        return $opportunity;
    }
}

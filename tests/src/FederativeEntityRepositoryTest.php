<?php

namespace Tests\ConectaEnte;

use ConectaEnte\Entities\FederativeEntity;
use ConectaEnte\Exceptions\DuplicateSealException;
use ConectaEnte\Repositories\FederativeEntityRepository;
use Doctrine\DBAL\Driver\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

class FederativeEntityRepositoryTest extends TestCase
{
    private $em;
    private $enteRepo;
    private $sealRepo;
    private $repository;

    protected function setUp(): void
    {
        $this->enteRepo = $this->createMock(EntityRepository::class);
        $this->sealRepo = $this->createMock(EntityRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->em->method('getRepository')->willReturnMap([
            [FederativeEntity::class, $this->enteRepo],
            [FederativeEntityRepository::SEAL_CLASS, $this->sealRepo],
        ]);

        $this->repository = new FederativeEntityRepository($this->em);
    }

    public function testFindBySealIdReturnsTheEntityWithTokenAndCnpj()
    {
        $ente = $this->ente(1, 'Município X', 10, 'tok', '12345678000199');
        $this->enteRepo->expects($this->once())->method('findOneBy')->with(['sealId' => 10])->willReturn($ente);

        $found = $this->repository->findBySealId('10');

        $this->assertSame($ente, $found);
        $this->assertSame('tok', $found->getToken());
        $this->assertSame('12345678000199', $found->getCnpj());
    }

    public function testDeletedSealDoesNotResolveToAnEntity()
    {
        $this->enteRepo->method('findOneBy')->willReturn(null);
        $this->sealRepo->method('find')->with(99)->willReturn(null);

        $this->assertNull($this->repository->findBySealId(99));
        $this->assertFalse($this->repository->sealExists(99));
    }

    public function testOpportunityConflictQueryScopesToOpportunitiesActiveStatusesAndOtherEntities()
    {
        $metadata = $this->createMock(\Doctrine\ORM\Mapping\ClassMetadata::class);
        $metadata->method('getTableName')->willReturn('conectaente_federative_entity');
        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $this->em->method('getClassMetadata')->with(FederativeEntity::class)->willReturn($metadata);
        $this->em->method('getConnection')->willReturn($connection);

        $connection->expects($this->once())->method('fetchAssoc')
            ->with(
                $this->callback(function ($sql) {
                    return strpos($sql, 'conectaente_federative_entity') !== false
                        && strpos($sql, 'IN (1,-5)') !== false
                        && strpos($sql, 'IN (1,-1)') !== false
                        && strpos($sql, 'fe.id <> :own') !== false;
                }),
                ['type' => 'MapasCulturais\\Entities\\Opportunity', 'seal' => 10, 'own' => 0]
            )
            ->willReturn(['opportunity_id' => '8452', 'entity_name' => 'Y']);

        $found = $this->repository->findOpportunityHoldingAnotherEntitySeal(10);

        $this->assertSame('Y', $found['entity_name']);
    }

    public function testOpportunityConflictIsNullWhenNothingConflicts()
    {
        $metadata = $this->createMock(\Doctrine\ORM\Mapping\ClassMetadata::class);
        $metadata->method('getTableName')->willReturn('t');
        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $connection->method('fetchAssoc')->willReturn(false);
        $this->em->method('getClassMetadata')->willReturn($metadata);
        $this->em->method('getConnection')->willReturn($connection);

        $this->assertNull($this->repository->findOpportunityHoldingAnotherEntitySeal(10, 3));
    }

    public function testSealExists()
    {
        $this->sealRepo->method('find')->with(10)->willReturn(new \stdClass());

        $this->assertTrue($this->repository->sealExists(10));
    }

    public function testSavesNewEntity()
    {
        $this->enteRepo->method('findOneBy')->willReturn(null);
        $ente = $this->ente(null, 'Novo', 10, 'tok', null);

        $this->em->expects($this->once())->method('persist')->with($ente);
        $this->em->expects($this->once())->method('flush');

        $this->assertSame($ente, $this->repository->save($ente));
    }

    public function testResavingTheSameEntityIsNotADuplicate()
    {
        $ente = $this->ente(5, 'Mesmo', 10, 'tok', null);
        $this->enteRepo->method('findOneBy')->willReturn($ente);

        $this->em->expects($this->once())->method('flush');

        $this->repository->save($ente);
    }

    public function testSealOfAnotherEntityThrowsNamingTheExistingOne()
    {
        $existente = $this->ente(5, 'Município Y', 10, 'tok', null);
        $this->enteRepo->method('findOneBy')->willReturn($existente);
        $this->em->expects($this->never())->method('flush');

        try {
            $this->repository->save($this->ente(null, 'Outro', 10, 'tok2', null));
            $this->fail('esperava DuplicateSealException');
        } catch (DuplicateSealException $e) {
            $this->assertSame('Município Y', $e->getExistingName());
            $this->assertSame(10, $e->getSealId());
            $this->assertStringContainsString('Município Y', $e->getMessage());
        }
    }

    public function testDatabaseUniqueViolationAlsoNamesTheExistingOne()
    {
        $existente = $this->ente(5, 'Município Y', 10, 'tok', null);
        // a checagem prévia não vê o concorrente; depois da violação, vê
        $this->enteRepo->method('findOneBy')->willReturnOnConsecutiveCalls(null, $existente);
        $this->em->method('flush')->willThrowException($this->uniqueViolation());

        $this->expectException(DuplicateSealException::class);
        $this->expectExceptionMessage('Município Y');

        $this->repository->save($this->ente(null, 'Outro', 10, 'tok2', null));
    }

    public function testFindAllOrdersByName()
    {
        $this->enteRepo->expects($this->once())->method('findBy')
            ->with([], ['name' => 'ASC', 'id' => 'ASC'])->willReturn([]);

        $this->assertSame([], $this->repository->findAll());
    }

    public function testDeleteRemovesAndFlushes()
    {
        $ente = $this->ente(5, 'X', 10, 'tok', null);

        $this->em->expects($this->once())->method('remove')->with($ente);
        $this->em->expects($this->once())->method('flush');

        $this->repository->delete($ente);
    }

    public function testTokenIsNotSerialized()
    {
        // jsonSerialize depende do App; garante só que a sobrescrita existe na própria classe
        $method = new \ReflectionMethod(FederativeEntity::class, 'jsonSerialize');
        $this->assertSame(FederativeEntity::class, $method->getDeclaringClass()->getName());
    }

    private function uniqueViolation()
    {
        $driver_exception = $this->createMock(DriverException::class);

        return new UniqueConstraintViolationException('duplicate key', $driver_exception);
    }

    // Entity::__construct precisa do App; os testes montam a entidade sem passar por ele
    private function ente($id, $name, $sealId, $token, $cnpj)
    {
        $ente = (new \ReflectionClass(FederativeEntity::class))->newInstanceWithoutConstructor();

        $set = function ($prop, $value) use ($ente) {
            $p = new \ReflectionProperty(FederativeEntity::class, $prop);
            $p->setAccessible(true);
            $p->setValue($ente, $value);
        };
        $set('id', $id);
        $set('name', $name);
        $set('sealId', $sealId);
        $set('token', $token);
        $set('cnpj', $cnpj);

        return $ente;
    }
}

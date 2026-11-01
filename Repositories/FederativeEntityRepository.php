<?php

namespace ConectaEnte\Repositories;

use ConectaEnte\Entities\FederativeEntity;
use ConectaEnte\Exceptions\DuplicateSealException;
use ConectaEnte\Services\OpportunitySealGuard;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use MapasCulturais\App;

class FederativeEntityRepository
{
    const SEAL_CLASS = 'MapasCulturais\Entities\Seal';
    const OPPORTUNITY_CLASS = 'MapasCulturais\Entities\Opportunity';

    private $em;

    public function __construct(EntityManagerInterface $em = null)
    {
        $this->em = $em ?: App::i()->em;
    }

    public function findBySealId($sealId)
    {
        return $this->em->getRepository(FederativeEntity::class)->findOneBy(['sealId' => (int) $sealId]);
    }

    public function findById($id)
    {
        return $this->em->getRepository(FederativeEntity::class)->find((int) $id);
    }

    public function findAll()
    {
        return $this->em->getRepository(FederativeEntity::class)->findBy([], ['name' => 'ASC', 'id' => 'ASC']);
    }

    public function save(FederativeEntity $entity)
    {
        $this->assertSealFree($entity);

        $entity->setUpdateTimestamp(new \DateTime());

        try {
            $this->em->persist($entity);
            $this->em->flush();
        } catch (UniqueConstraintViolationException $e) {
            // corrida entre a checagem e o INSERT: a UNIQUE do banco é a garantia final
            $this->assertSealFree($entity);
            throw $e;
        }

        return $entity;
    }

    public function delete(FederativeEntity $entity)
    {
        $this->em->remove($entity);
        $this->em->flush();
    }

    /**
     * Oportunidade que já tem o selo informado e também o selo de outro ente: registrar o selo como de
     * um ente faria a oportunidade acumular dois (#44). Usa o mesmo critério do `OpportunitySealGuard`
     * para relação e selo que contam.
     *
     * @param int      $sealId selo que viraria selo de ente
     * @param int|null $ownId  ente que está sendo editado (o selo dele não conta como "outro")
     *
     * @return array|null `opportunity_id` e `entity_name` do primeiro conflito; null quando não há
     */
    public function findOpportunityHoldingAnotherEntitySeal($sealId, $ownId = null)
    {
        $table = $this->em->getClassMetadata(FederativeEntity::class)->getTableName();
        $relations = implode(',', array_map('intval', OpportunitySealGuard::RELATION_STATUSES));
        $seals = implode(',', array_map('intval', OpportunitySealGuard::SEAL_STATUSES));

        $sql = "SELECT mine.object_id AS opportunity_id, fe.name AS entity_name
                FROM seal_relation mine
                JOIN seal mine_seal ON mine_seal.id = mine.seal_id
                JOIN seal_relation other ON other.object_id = mine.object_id
                    AND other.object_type = mine.object_type
                    AND other.seal_id <> mine.seal_id
                JOIN seal other_seal ON other_seal.id = other.seal_id
                JOIN {$table} fe ON fe.seal_id = other.seal_id
                WHERE mine.object_type = :type
                    AND mine.seal_id = :seal
                    AND fe.id <> :own
                    AND mine.status IN ({$relations}) AND mine_seal.status IN ({$seals})
                    AND other.status IN ({$relations}) AND other_seal.status IN ({$seals})
                ORDER BY mine.object_id
                LIMIT 1";

        $row = $this->em->getConnection()->fetchAssoc($sql, [
            'type' => self::OPPORTUNITY_CLASS,
            'seal' => (int) $sealId,
            'own' => (int) $ownId,
        ]);

        return $row ?: null;
    }

    public function sealExists($sealId)
    {
        return $this->em->getRepository(self::SEAL_CLASS)->find((int) $sealId) !== null;
    }

    private function assertSealFree(FederativeEntity $entity)
    {
        $existing = $this->findBySealId($entity->getSealId());

        if ($existing !== null && $existing->getId() !== $entity->getId()) {
            throw new DuplicateSealException($entity->getSealId(), $existing->getName());
        }
    }
}

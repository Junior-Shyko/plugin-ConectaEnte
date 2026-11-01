<?php

namespace ConectaEnte\Services;

use ConectaEnte\Entities\FederativeEntity;
use ConectaEnte\Repositories\FederativeEntityRepository;

class SealedOpportunity
{
    private $repository;

    public function __construct(FederativeEntityRepository $repository)
    {
        $this->repository = $repository;
    }

    public function isSealed($opportunity)
    {
        return $this->entityFor($opportunity) !== null;
    }

    public function entityFor($opportunity)
    {
        foreach ($opportunity->getSealRelations() as $relation) {
            $entity = $this->repository->findBySealId($relation->seal->id);

            if ($entity !== null) {
                return $entity;
            }
        }

        return null;
    }
}

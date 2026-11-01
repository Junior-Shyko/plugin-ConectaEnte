<?php

namespace ConectaEnte\Services;

use ConectaEnte\Repositories\FederativeEntityRepository;
use MapasCulturais\Entities\Seal;
use MapasCulturais\Entities\SealRelation;
use MapasCulturais\i;

class OpportunitySealGuard
{
    const RELATION_STATUSES = [SealRelation::STATUS_ENABLED, SealRelation::STATUS_PENDING];
    const SEAL_STATUSES = [Seal::STATUS_ENABLED, Seal::STATUS_RELATED];

    private $repository;

    public function __construct(FederativeEntityRepository $repository)
    {
        $this->repository = $repository;
    }

    public function conflict($newSealId, array $applied)
    {
        $incoming = $this->repository->findBySealId($newSealId);

        if ($incoming === null) {
            return null;
        }

        foreach ($this->appliedSealIds($applied) as $sealId) {
            if ($sealId === (int) $newSealId) {
                continue;
            }

            $other = $this->repository->findBySealId($sealId);

            if ($other !== null && $other->getId() !== $incoming->getId()) {
                return sprintf(
                    $this->t('Não é possível aplicar o selo do ente "%s": esta oportunidade já tem o selo do ente "%s". Uma oportunidade só pode ter o selo de um ente federado.'),
                    $incoming->getName(),
                    $other->getName()
                );
            }
        }

        return null;
    }

    /**
     * Mesmo critério do core (`EntitySealRelation::getSealRelations`): conta a relação habilitada e a
     * pendente de aprovação (dois pedidos pendentes de entes diferentes virariam dois habilitados), e só
     * de selo ativo; a de selo arquivado não aparece na tela e ninguém conseguiria removê-la.
     *
     * @return int[]
     */
    private function appliedSealIds(array $applied)
    {
        $ids = [];

        foreach ($applied as $relation) {
            if (in_array($relation['status'], self::RELATION_STATUSES, true)
                && in_array($relation['seal_status'], self::SEAL_STATUSES, true)) {
                $ids[] = (int) $relation['seal_id'];
            }
        }

        return array_values(array_unique($ids));
    }

    private function t($text)
    {
        return class_exists(i::class) ? i::__($text) : $text;
    }
}

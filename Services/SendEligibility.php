<?php

namespace ConectaEnte\Services;

use ConectaEnte\Dto\ParInformation;
use ConectaEnte\Entities\FederativeEntity;
use ConectaEnte\Payload\OpportunityPayload;
use ConectaEnte\Repositories\FederativeEntityRepository;
use MapasCulturais\Entities\Opportunity;

/**
 * Regra única de quais oportunidades vão ao CultBR. Vale para o envio automático e para a execução do job,
 * porque o estado pode ter mudado entre o enfileiramento e a execução.
 *
 * Só a raiz representa o edital: fase nunca é elegível, e os hooks levam a raiz para cá.
 */
class SendEligibility
{
    private $repository;
    private $missingFields;
    private $parTree;

    /**
     * @param callable|null $missingFields ($opportunity, FederativeEntity, ParInformation|null) → string[] do que falta
     * @param callable|null $parTree       FederativeEntity → ParInformation|null: a árvore do PAR em cache, sem API
     */
    public function __construct(FederativeEntityRepository $repository, callable $missingFields = null, callable $parTree = null)
    {
        $this->repository = $repository;
        $this->missingFields = $missingFields ?: [self::class, 'missingInPublication'];
        $this->parTree = $parTree;
    }

    /**
     * @param Opportunity $opportunity
     */
    public function check($opportunity)
    {
        if ($opportunity->parent) {
            return EligibilityResult::ineligible(EligibilityResult::IS_PHASE);
        }

        if (in_array((int) $opportunity->status, [Opportunity::STATUS_ARCHIVED, Opportunity::STATUS_TRASH], true)) {
            return EligibilityResult::ineligible(EligibilityResult::ARCHIVED_OR_TRASH);
        }

        if ((int) $opportunity->status !== Opportunity::STATUS_ENABLED) {
            return EligibilityResult::ineligible(EligibilityResult::NOT_PUBLISHED);
        }

        $relations = $opportunity->getSealRelations();

        if (!$relations) {
            return EligibilityResult::ineligible(EligibilityResult::NO_SEAL);
        }

        $entity = $this->entityFor($relations);

        if ($entity === null) {
            return EligibilityResult::ineligible(EligibilityResult::SEAL_WITHOUT_ENTITY);
        }

        $tree = $this->parTree ? call_user_func($this->parTree, $entity) : null;
        $missing = call_user_func($this->missingFields, $opportunity, $entity, $tree);

        if ($missing) {
            return EligibilityResult::ineligible(EligibilityResult::MISSING_FIELDS, $missing);
        }

        return EligibilityResult::eligible($entity);
    }

    private function entityFor(array $relations)
    {
        foreach ($relations as $relation) {
            $entity = $this->repository->findBySealId($relation->seal->id);

            if ($entity !== null) {
                return $entity;
            }
        }

        return null;
    }

    /**
     * Requisitos do edital (PT-BR, validados no publish, com a cadeia do PAR se houver árvore) mais os campos do
     * contrato que o payload deixa nulos.
     */
    public static function missingInPublication($opportunity, FederativeEntity $entity, ParInformation $tree = null)
    {
        $requirements = array_keys((new PublicationRequirements())->missingFields($opportunity, $tree));
        $payload = new OpportunityPayload();

        return array_merge($requirements, $payload->missing($payload->build($opportunity, $entity)));
    }
}

<?php

namespace ConectaEnte\Listeners;

use ConectaEnte\Plugin;
use Doctrine\ORM\Event\LifecycleEventArgs;
use MapasCulturais\Entities\OpportunitySealRelation;
use MapasCulturais\Exceptions\BadRequest;

/**
 * Um ente por oportunidade (#44), para todo caminho que grava a relação.
 *
 * É um listener do Doctrine, e não o hook `entity(OpportunitySealRelation).save:before`: no core da
 * v5 só as entidades com `@ORM\HasLifecycleCallbacks` (Opportunity, Seal, Agent...) disparam os hooks
 * `save:before`/`save:after`, e `SealRelation` não declara os callbacks. O `prePersist` roda dentro do
 * `persist()`, antes do `flush`, então lançar aqui não fecha o EntityManager. Só relações novas passam
 * por ele: o selo de uma relação existente nunca muda (`SealRelation::setSeal` recusa).
 */
class SingleEntityPerOpportunityListener
{
    public function prePersist(LifecycleEventArgs $args)
    {
        $relation = $args->getEntity();

        if (!$relation instanceof OpportunitySealRelation) {
            return;
        }

        $message = Plugin::sealConflictMessage($relation->owner, $relation->seal, $relation);

        if ($message !== null) {
            throw new BadRequest($message);
        }
    }
}

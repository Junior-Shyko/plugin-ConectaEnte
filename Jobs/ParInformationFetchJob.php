<?php

namespace ConectaEnte\Jobs;

use ConectaEnte\Entities\FederativeEntity;
use ConectaEnte\Plugin;
use ConectaEnte\Repositories\FederativeEntityRepository;
use MapasCulturais\App;
use MapasCulturais\Definitions\JobType;
use MapasCulturais\Entities\Job;

/**
 * Leva ao cache a árvore do PAR de um ente. Um job por ente (o id deriva do id do ente).
 *
 * Sempre devolve true: `false` deixa a linha presa em "processando" para sempre.
 */
class ParInformationFetchJob extends JobType
{
    // a coluna do slug no core tem 32 caracteres
    const SLUG = 'conectaente-par-fetch';

    public static function dataFor(FederativeEntity $entity)
    {
        return ['federativeEntityId' => $entity->getId()];
    }

    protected function _generateId(array $data, string $start_string, string $interval_string, int $iterations)
    {
        return 'conectaente-par-fetch:' . $data['federativeEntityId'];
    }

    protected function _execute(Job $job)
    {
        try {
            $entity = (new FederativeEntityRepository())->findById((int) $job->federativeEntityId);

            // entre o enfileiramento e a execução o ente pode ter sido apagado
            if ($entity) {
                Plugin::parInformationService()->fetch($entity);
            }
        } catch (\Throwable $e) {
            App::i()->log->error("[ConectaEnte] busca da árvore do PAR do ente {$job->federativeEntityId} falhou: {$e->getMessage()}");
        }

        return true;
    }
}

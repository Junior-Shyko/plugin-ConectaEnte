<?php

namespace ConectaEnte\Jobs;

use ConectaEnte\Repositories\FederativeEntityRepository;
use MapasCulturais\App;
use MapasCulturais\Definitions\JobType;
use MapasCulturais\Entities\Job;

/**
 * Mantém a árvore do PAR de todos os entes: enfileira uma busca por ente, espaçadas de um segundo.
 *
 * Periódico pela recorrência do core: o job é reagendado com o intervalo do TTL, até `ITERATIONS` vezes.
 * Agendado pelo cadastro do token (Plugin::scheduleParInformation); o id fixo evita duplicar.
 */
class ParInformationSyncJob extends JobType
{
    const SLUG = 'conectaente-par-sync';
    const SPACING_SECONDS = 1;
    const ITERATIONS = 100000;

    protected function _generateId(array $data, string $start_string, string $interval_string, int $iterations)
    {
        return self::SLUG;
    }

    protected function _execute(Job $job)
    {
        $app = App::i();

        foreach ((new FederativeEntityRepository())->findAll() as $position => $entity) {
            $start = '+' . ($position * self::SPACING_SECONDS) . ' seconds';

            $app->enqueueOrReplaceJob(ParInformationFetchJob::SLUG, ParInformationFetchJob::dataFor($entity), $start, '', 1);
        }

        return true;
    }
}

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
 * Agendado por Plugin::ensureParSync(), que não o substitui enquanto ele existir.
 *
 * Sempre devolve true: falha em um ente é logada e não interrompe os outros nem a recorrência.
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

        try {
            foreach ((new FederativeEntityRepository())->findAll() as $position => $entity) {
                $start = '+' . ($position * self::SPACING_SECONDS) . ' seconds';

                try {
                    $app->enqueueJob(ParInformationFetchJob::SLUG, ParInformationFetchJob::dataFor($entity), $start, '', 1);
                } catch (\Throwable $e) {
                    $app->log->error("[ConectaEnte] sincronização do PAR não enfileirou o ente {$entity->getId()}: {$e->getMessage()}");
                }
            }
        } catch (\Throwable $e) {
            $app->log->error("[ConectaEnte] sincronização do PAR falhou: {$e->getMessage()}");
        }

        return true;
    }
}

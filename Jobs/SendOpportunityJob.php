<?php

namespace ConectaEnte\Jobs;

use ConectaEnte\Config;
use ConectaEnte\Metadata\CultBrMetadata;
use ConectaEnte\Plugin;
use ConectaEnte\Repositories\FederativeEntityRepository;
use ConectaEnte\Services\OpportunitySender;
use ConectaEnte\Services\SendEligibility;
use ConectaEnte\Http\Client;
use ConectaEnte\Payload\OpportunityPayload;
use ConectaEnte\Services\ParEditalOwnership;
use ConectaEnte\Services\SendLogService;
use ConectaEnte\Services\SendReport;
use ConectaEnte\Vocabulary\SendOutcome;
use DateTime;
use MapasCulturais\App;
use MapasCulturais\Definitions\JobType;
use MapasCulturais\Entities\Job;

/**
 * Envio de uma oportunidade ao CultBR. Um job por oportunidade (o id é derivado do id dela), então N saves
 * seguidos viram um envio só.
 *
 * Retentativa: o próprio job é reagendado pelo core, com `iterations` = máximo de tentativas e
 * `intervalString` = atraso. O contador de tentativas é o `iterationsCount` do core, que sobe a cada execução.
 * Um reenfileiramento novo cairia no mesmo id do job em execução e seria descartado, por isso não é usado.
 */
class SendOpportunityJob extends JobType
{
    const SLUG = 'conectaente-send-opportunity';

    protected function _generateId(array $data, string $start_string, string $interval_string, int $iterations)
    {
        return 'conectaente-send:' . $data['opportunityId'];
    }

    /**
     * Sempre devolve true: `false` deixa a linha presa em "processando" para sempre.
     */
    protected function _execute(Job $job)
    {
        $app = App::i();
        $attempt = (int) $job->iterationsCount + 1;
        $maxAttempts = Config::maxAttempts();
        $logs = self::sendLogs();
        $uuid = null;

        try {
            $opportunity = $app->repo('Opportunity')->find((int) $job->opportunityId);
            $entity = (new FederativeEntityRepository())->findById((int) $job->entityId);

            if (!$opportunity || !$entity) {
                // numa retentativa o envio já está aberto: sem encerrá-lo, ficaria pending para sempre
                if ($job->requestUuid) {
                    $logs->finish($job->requestUuid, SendOutcome::ABANDONED);
                }

                return $this->finish($job, SendOutcome::ABANDONED);
            }

            $uuid = $this->requestUuid($job);
            $logs->startOrResume($uuid, $opportunity->id, $job->userId);

            $sender = new OpportunitySender(Client::fromConfig(), new OpportunityPayload(), new SendEligibility(new FederativeEntityRepository(), null, [Plugin::class, 'cachedParTree']), Config::isLive());
            $report = $sender->send($opportunity, $entity, $attempt, Config::maxAttempts());

            $logs->recordAttempt($uuid, $attempt, $maxAttempts, $report);

            if ($report->getOutcome() === SendOutcome::PENDING) {
                // o core soma o intervalo a partir de nextExecutionTimestamp: "agora" faz a retentativa cair em +15s
                $job->nextExecutionTimestamp = new DateTime();
                $app->log->warning("[ConectaEnte] envio da oportunidade {$job->opportunityId} tentativa {$attempt}: {$report->getMessage()}");

                return true;
            }

            $logs->finish($uuid, $report->getOutcome());
            $this->recordCorrelation($opportunity, $report);

            return $this->finish($job, $report->getOutcome(), $report->getMessage());
        } catch (\Throwable $e) {
            $app->log->error("[ConectaEnte] envio da oportunidade {$job->opportunityId} falhou: {$e->getMessage()}");

            if ($uuid !== null) {
                $logs->recordAttempt($uuid, $attempt, $maxAttempts, new SendReport(SendOutcome::ERROR, $e->getMessage()));
                $logs->finish($uuid, SendOutcome::ERROR);
            }

            return $this->finish($job, SendOutcome::ERROR);
        }
    }

    public static function sendLogs()
    {
        $app = App::i();

        return new SendLogService($app->em->getConnection(), function ($message) use ($app) {
            $app->log->error($message);
        });
    }

    /**
     * O UUID nasce na 1ª tentativa e fica no `metadata` do job: as retentativas caem no mesmo envio.
     * O core grava o job de novo ao reagendá-lo, e é nessa gravação que o UUID persiste.
     */
    private function requestUuid(Job $job)
    {
        if (!$job->requestUuid) {
            $job->requestUuid = SendLogService::newUuid();
        }

        return $job->requestUuid;
    }

    /**
     * O `id_par_edital` e o `x-request-id` são a chave de correlação com o CultBR. Fora do try principal:
     * uma falha ao gravar não pode transformar um envio aceito em erro.
     */
    private function recordCorrelation($opportunity, SendReport $report)
    {
        if ($report->getOutcome() !== SendOutcome::SUCCESS) {
            return;
        }

        try {
            if ($report->getParEditalId() !== null) {
                $owner = (new ParEditalOwnership(App::i()->em->getConnection()))->otherOwner($report->getParEditalId(), $opportunity->id);

                // o índice único do banco recusaria a gravação: aqui vira um erro legível no log
                if ($owner !== null) {
                    App::i()->log->error("[ConectaEnte] o id_par_edital {$report->getParEditalId()} já pertence à oportunidade {$owner}; a oportunidade {$opportunity->id} não o recebe (request_id: {$report->getRequestId()})");

                    return;
                }

                $opportunity->setMetadata(CultBrMetadata::PAR_EDITAL_ID, $report->getParEditalId());
            }

            // sempre grava (null limpa): o request id é do mesmo envio do id_par_edital, não pode sobrar o de um envio anterior
            $opportunity->setMetadata(CultBrMetadata::REQUEST_ID, $report->getRequestId());

            $opportunity->saveMetadata();
        } catch (\Throwable $e) {
            App::i()->log->error("[ConectaEnte] envio aceito, mas não foi possível gravar a correlação (id_par_edital: {$report->getParEditalId()}, request_id: {$report->getRequestId()}): {$e->getMessage()}");
        }
    }

    /**
     * Encerra o job: definir o contador no máximo faz o core removê-lo ao concluir a execução.
     */
    private function finish(Job $job, $outcome, $message = null)
    {
        $job->iterationsCount = $job->iterations;

        App::i()->log->info("[ConectaEnte] envio da oportunidade {$job->opportunityId}: {$outcome}" . ($message ? " ({$message})" : ''));

        return true;
    }
}

<?php

namespace ConectaEnte;

use ConectaEnte\Entities\FederativeEntity;
use ConectaEnte\Http\Client;
use ConectaEnte\Http\ParInformationResult;
use ConectaEnte\Jobs\ParInformationFetchJob;
use ConectaEnte\Jobs\ParInformationSyncJob;
use ConectaEnte\Jobs\SendOpportunityJob;
use ConectaEnte\Metadata\CultBrMetadata;
use ConectaEnte\Repositories\FederativeEntityRepository;
use ConectaEnte\Repositories\SendLogRepository;
use ConectaEnte\Services\FederativeEntityService;
use ConectaEnte\Services\OpportunitySealGuard;
use ConectaEnte\Services\ParInformationService;
use ConectaEnte\Services\PublicationRequirements;
use ConectaEnte\Services\PublicationStamp;
use ConectaEnte\Services\SendEligibility;
use ConectaEnte\Services\SealedOpportunity;
use Doctrine\ORM\Events;
use MapasCulturais\App;
use MapasCulturais\Entities\OpportunitySealRelation;
use MapasCulturais\Entities\Seal;
use MapasCulturais\Entities\SealRelation;
use MapasCulturais\Exceptions\PermissionDenied;
use MapasCulturais\i;

class Plugin extends \MapasCulturais\Plugin
{
    public function _init()
    {
        $app = App::i();

        $this->hookSingleEntityPerOpportunity($app);
        $this->hookCultBrTab($app);
        $this->hookPublicationRequirements($app);
        $this->hookPublicationStamp($app);
        $this->hookSendOpportunity($app);
        $this->hookParInformation($app);

        $app->log->debug('[ConectaEnte] _init() mode=' . Config::mode());

        // o menu do painel não tem API: o callback escreve o <li> direto
        $app->hook('panel.menu:after', function () use ($app) {
            if (!Plugin::canManage()) {
                return;
            }

            $active = $this->template == 'panel/federative-entities' ? 'active' : '';
            $url = $app->createUrl('panel', 'federative-entities');

            echo "<li><a class='$active' href='$url' rel='noopener noreferrer'><span class='icon icon-seal'></span> Entes Federados</a></li>";
        });

        $app->hook('GET(panel.federative-entities)', function () use ($app) {
            $this->requireAuthentication();

            if (!Plugin::canManage()) {
                throw new PermissionDenied($app->user, null, 'view');
            }

            $app->view->enqueueStyle('app', 'conectaente-federative-entities', 'css/conecta-ente/federative-entities.css');
            $app->view->enqueueScript('app', 'conectaente-federative-entities', 'js/conecta-ente/federative-entities.js', ['mapasculturais']);

            $repository = new FederativeEntityRepository();
            $entities = $repository->findAll();

            // o token nunca vai para a tela: só o indicador e o final dele, pela mesma regra da API
            $service = new FederativeEntityService($repository, function () {
                return true;
            });
            $tokens = [];
            foreach ($entities as $entity) {
                $tokens[$entity->getId()] = $service->present($entity);
            }

            $seals = [];
            foreach ($app->repo('Seal')->findBy(['status' => Seal::STATUS_ENABLED], ['name' => 'ASC']) as $seal) {
                $seals[$seal->id] = $seal->name;
            }

            // o selo de um ente pode ter saído da lista de ativos (arquivado, rascunho): o nome ainda aparece
            foreach ($entities as $entity) {
                if (!isset($seals[$entity->getSealId()])) {
                    $seal = $app->repo('Seal')->find($entity->getSealId());
                    $seals[$entity->getSealId()] = $seal ? $seal->name : '#' . $entity->getSealId();
                }
            }

            $this->render('federative-entities', [
                'entities' => $entities,
                'tokens' => $tokens,
                'activeSeals' => $seals,
                'usedSealIds' => array_map(function ($entity) {
                    return $entity->getSealId();
                }, $entities),
                'simulated' => !Config::isLive(),
            ]);
        });
    }

    private function hookSingleEntityPerOpportunity(App $app)
    {
        // defesa de todos os caminhos que gravam a relação; o core não traduz o BadRequest em resposta HTTP
        $app->em->getEventManager()->addEventListener([Events::prePersist], new Listeners\SingleEntityPerOpportunityListener());

        // a tela de selos da oportunidade chama esta ação: aqui o motivo chega ao usuário como 400 em JSON
        $app->hook('POST(opportunity.createSealRelation):before', function () use ($app) {
            if (empty($this->urlData['id']) || !isset($this->postData['sealId'])) {
                return;
            }

            $opportunity = $this->repository->find($this->urlData['id']);
            $seal = $app->repo('Seal')->find($this->postData['sealId']);

            // sem permissão sobre a oportunidade e sobre o selo (o core exige as duas), quem responde é
            // o core (403): nada do ente vaza antes
            if (!$opportunity || !$seal || !$opportunity->canUser('createSealRelation') || !$seal->canUser('@control')) {
                return;
            }

            $message = Plugin::sealConflictMessage($opportunity, $seal);

            if ($message !== null) {
                $this->errorJson($message, 400);
            }
        });
    }

    public static function sealConflictMessage($opportunity, $seal, SealRelation $ignored = null)
    {
        $app = App::i();
        $relations = $app->repo('OpportunitySealRelation')->findBy(['owner' => $opportunity]);

        // relações agendadas e ainda sem flush (`createSealRelation($seal, true, false)`) não estão no banco
        foreach ($app->em->getUnitOfWork()->getScheduledEntityInsertions() as $scheduled) {
            if ($scheduled instanceof OpportunitySealRelation && $scheduled->owner === $opportunity) {
                $relations[] = $scheduled;
            }
        }

        $applied = [];

        foreach ($relations as $relation) {
            if ($relation !== $ignored) {
                $applied[] = [
                    'seal_id' => $relation->seal->id,
                    'status' => $relation->status,
                    'seal_status' => $relation->seal->status,
                ];
            }
        }

        return (new OpportunitySealGuard(new FederativeEntityRepository()))->conflict($seal->id, $applied);
    }

    public function register()
    {
        $app = App::i();

        $app->registerController('conecta-ente', Controllers\ConectaEnteController::class);

        CultBrMetadata::register($this);
        $this->registerOpportunityMetadata(CultBrMetadata::PAR_EDITAL_ID, ['label' => i::__('ID do edital no CultBR'), 'type' => 'string']);
        $this->registerOpportunityMetadata(CultBrMetadata::REQUEST_ID, ['label' => i::__('Request ID do último envio aceito pelo CultBR'), 'type' => 'string']);
        $this->registerOpportunityMetadata(CultBrMetadata::PUBLICATION_DATE, [
            'label' => i::__('Data de publicação do edital'),
            'type' => 'date',
        ]);

        $app->log->debug('[ConectaEnte] register()');
    }

    private function hookPublicationStamp(App $app)
    {
        $app->hook('entity(Opportunity).save:before', function () {
            (new PublicationStamp())->stamp($this);
        });
    }

    private function hookSendOpportunity(App $app)
    {
        $app->registerJobType(new SendOpportunityJob(SendOpportunityJob::SLUG));

        $app->hook('entity(Opportunity).update:finish', function () {
            Plugin::scheduleSend($this);
        });

        // selar uma oportunidade já publicada não passa pelo save da oportunidade: a relação tem o próprio gatilho
        $app->hook('entity(OpportunitySealRelation).save:finish', function () {
            Plugin::scheduleSend($this->owner);
        });
    }

    private function hookParInformation(App $app)
    {
        $app->registerJobType(new ParInformationFetchJob(ParInformationFetchJob::SLUG));
        $app->registerJobType(new ParInformationSyncJob(ParInformationSyncJob::SLUG));
    }

    public static function parInformationService()
    {
        $app = App::i();
        $identity = Config::mode() . '|' . Config::host();

        return new ParInformationService(Client::fromConfig(), $app->mscache, Config::parTtl(), $identity, function ($message) use ($app) {
            $app->log->warning('[ConectaEnte] ' . $message);
        });
    }

    /**
     * Cadastro com token novo ou reverificado: descarta a árvore antiga, busca a nova agora e garante a
     * sincronização periódica (sem adiar a que já existe). Falha de agendamento é logada e não derruba o cadastro.
     */
    public static function scheduleParInformation(FederativeEntity $entity)
    {
        try {
            self::parInformationService()->forget($entity);
        } catch (\Throwable $e) {
            App::i()->log->error("[ConectaEnte] could not forget the PAR tree of federative entity {$entity->getId()}: {$e->getMessage()}");
        }

        self::requestParFetch($entity, true);
        self::ensureParSync();
    }

    /**
     * Enfileira a busca de um ente, sem esperar pela API. Sem `$force`, no máximo uma por minuto por ente:
     * a tela pode ser recarregada várias vezes enquanto a API está fora.
     */
    public static function requestParFetch(FederativeEntity $entity, $force = false)
    {
        $app = App::i();
        $flag = 'conectaente:par-fetch-requested:' . $entity->getId();

        if (!$force && $app->mscache->contains($flag)) {
            return;
        }

        try {
            $app->enqueueJob(ParInformationFetchJob::SLUG, ParInformationFetchJob::dataFor($entity), 'now', '', 1);
            $app->mscache->save($flag, true, 60);
        } catch (\Throwable $e) {
            $app->log->error("[ConectaEnte] could not queue the PAR tree of federative entity {$entity->getId()}: {$e->getMessage()}");
        }
    }

    /**
     * Garante uma única sincronização periódica. O flag dura um TTL, a mesma cadência do job: instalações
     * que já tinham entes ganham o job na primeira consulta da tela, sem reagendar a cada requisição.
     */
    public static function ensureParSync()
    {
        $app = App::i();
        $flag = 'conectaente:par-sync-scheduled';

        if ($app->mscache->contains($flag)) {
            return;
        }

        try {
            $ttl = Config::parTtl();
            $app->enqueueOrReplaceJob(ParInformationSyncJob::SLUG, [], "+{$ttl} seconds", "+{$ttl} seconds", ParInformationSyncJob::ITERATIONS);
            $app->mscache->save($flag, true, $ttl);
        } catch (\Throwable $e) {
            $app->log->error("[ConectaEnte] could not schedule the PAR sync: {$e->getMessage()}");
        }
    }

    private static function currentUserId()
    {
        $user = App::i()->user;

        return $user && !$user->is('guest') ? (int) $user->id : null;
    }

    /**
     * Enfileira o envio da raiz, se ela for elegível. Editar uma fase reenvia a raiz (decisão de #48).
     */
    public static function scheduleSend($opportunity)
    {
        $root = $opportunity;

        while ($root->parent) {
            $root = $root->parent;
        }

        // roda depois do flush: uma exceção aqui não pode virar 500 para uma oportunidade já salva
        try {
            $eligibility = (new SendEligibility(new FederativeEntityRepository(), null, [self::class, 'cachedParTree']))->check($root);

            if (!$eligibility->isEligible()) {
                return;
            }

            App::i()->enqueueOrReplaceJob(
                SendOpportunityJob::SLUG,
                ['opportunityId' => $root->id, 'entityId' => $eligibility->getEntity()->getId(), 'userId' => self::currentUserId()],
                'now',
                Config::retryDelay(),
                Config::maxAttempts()
            );
        } catch (\Throwable $e) {
            App::i()->log->error("[ConectaEnte] could not schedule the send of opportunity {$root->id}: {$e->getMessage()}");
        }
    }

    private function hookPublicationRequirements(App $app)
    {
        // roda antes do flush: parar aqui não fecha o EntityManager, e a entidade já está com o que o PUT enviou
        $app->hook('entity(Opportunity).publish:before', function () use ($app) {
            $entity = $this->parent === null ? Plugin::sealedEntity($this) : null;

            if ($entity === null) {
                return;
            }

            $missing = (new PublicationRequirements())->missingFields($this, Plugin::cachedParTree($entity));

            if ($missing) {
                [$status, $body] = Plugin::publicationErrorResponse($missing, $app->request->isAjax());

                $app->contentType('application/json');
                $app->halt($status, $body);
            }
        });
    }

    /**
     * A árvore do PAR do ente, só se estiver no cache. A publicação nunca chama a API: sem árvore, a cadeia
     * não é checada, e a tela já mostra o aviso e o botão de atualizar.
     */
    public static function cachedParTree(FederativeEntity $entity)
    {
        $result = self::parInformationService()->cached($entity);

        return $result->getState() === ParInformationResult::OK ? $result->getTree() : null;
    }

    /**
     * Pendências na publicação. O front (AJAX, como o editable.js) recebe 200 com `error: true`, o formato de
     * validação do core, porque um 400 cairia no `error:` do jQuery e perderia os campos. Quem não é o front
     * recebe 400, para que um cliente de API não confunda o bloqueio com uma publicação feita.
     *
     * @return array [status, corpo JSON]
     */
    public static function publicationErrorResponse(array $missing, $ajax)
    {
        return [$ajax ? 200 : 400, json_encode(['error' => true, 'data' => $missing])];
    }

    private function hookCultBrTab(App $app)
    {
        // os scripts saem no <head>, antes de o template rodar: enfileirar tem de ser antes da renderização
        $app->hook('GET(opportunity.edit):before', function () use ($app) {
            $opportunity = empty($this->urlData['id']) ? null : $this->repository->find($this->urlData['id']);

            if ($opportunity === null || Plugin::sealedEntity($opportunity) === null) {
                return;
            }

            $app->view->enqueueStyle('app', 'conectaente-opportunity-blocks', 'css/conecta-ente/opportunity-blocks.css');
            $app->view->enqueueScript('app', 'conectaente-opportunity-blocks', 'js/conecta-ente/opportunity-blocks.js', ['mapasculturais']);
            $app->view->enqueueScript('app', 'conectaente-par-cascade', 'js/conecta-ente/par-cascade.js', ['entity.module.opportunity']);
        });

        $app->hook('template(opportunity.edit.tabs):end', function () {
            if (Plugin::sealedEntity($this->controller->requestedEntity) === null) {
                return;
            }

            $this->part('tab', ['id' => 'conecta-ente-cultbr', 'label' => i::__('Ente Federado / CultBR')]);
        });

        $app->hook('template(opportunity.edit.tabs-content):end', function () {
            $opportunity = $this->controller->requestedEntity;

            if (Plugin::sealedEntity($opportunity) === null) {
                return;
            }

            $this->part('conecta-ente/opportunity-cultbr', ['entity' => $opportunity]);
        });

        $this->hookSendHistoryTab($app);
    }

    // aba própria (#55): fica onde há ente selado ou histórico, porque tirar o selo não apaga o que já foi enviado
    private function hookSendHistoryTab(App $app)
    {
        $app->hook('GET(opportunity.edit):before', function () use ($app) {
            $opportunity = empty($this->urlData['id']) ? null : $this->repository->find($this->urlData['id']);

            if ($opportunity === null || !Plugin::showsSendHistory($opportunity)) {
                return;
            }

            $app->view->enqueueStyle('app', 'conectaente-send-history', 'css/conecta-ente/send-history.css');
            $app->view->enqueueScript('app', 'conectaente-send-history', 'js/conecta-ente/send-history.js', ['entity.module.opportunity']);
        });

        $app->hook('template(opportunity.edit.tabs):end', function () {
            if (!Plugin::showsSendHistory($this->controller->requestedEntity)) {
                return;
            }

            $this->part('tab', ['id' => 'conecta-ente-send-history', 'label' => i::__('Histórico de envio')]);
        });

        $app->hook('template(opportunity.edit.tabs-content):end', function () {
            $opportunity = $this->controller->requestedEntity;

            if (!Plugin::showsSendHistory($opportunity)) {
                return;
            }

            $this->part('conecta-ente/opportunity-send-history', ['entity' => $opportunity]);
        });
    }

    public static function showsSendHistory($opportunity)
    {
        return self::sealedEntity($opportunity) !== null || (new SendLogRepository())->hasAny($opportunity->id);
    }

    public static function sealedEntity($opportunity)
    {
        return (new SealedOpportunity(new FederativeEntityRepository()))->entityFor($opportunity);
    }

    /**
     * Com a oportunidade viva, quem a edita vê o histórico. O histórico sobrevive à exclusão dela (sem FK),
     * e sem oportunidade não há permissão de edição para conferir: só o saasSuperAdmin.
     */
    public static function canViewSendHistory($opportunityId)
    {
        $opportunity = App::i()->repo('Opportunity')->find((int) $opportunityId);

        if ($opportunity) {
            return $opportunity->canUser('modify');
        }

        return self::canManage();
    }

    public static function canManage()
    {
        $user = App::i()->user;

        return !$user->is('guest') && $user->is('saasSuperAdmin');
    }
}

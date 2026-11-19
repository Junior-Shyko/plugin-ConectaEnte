<?php

namespace ConectaEnte\Controllers;

use ConectaEnte\Http\ApiResult;
use ConectaEnte\Repositories\FederativeEntityRepository;
use ConectaEnte\Entities\FederativeEntity;
use ConectaEnte\Http\ParInformationResult;
use ConectaEnte\Plugin;
use ConectaEnte\Repositories\SendLogRepository;
use ConectaEnte\Services\SendHistoryService;
use ConectaEnte\Services\FederativeEntityService;
use MapasCulturais\App;

class ConectaEnteController extends \MapasCulturais\Controller
{
    public function GET_index()
    {
        $this->respond($this->service()->all());
    }

    public function GET_list()
    {
        $this->GET_index();
    }

    public function POST_create()
    {
        $this->respond($this->service()->create($this->input()));
    }

    public function POST_verifyToken()
    {
        $this->respond($this->service()->verifyToken($this->input()));
    }

    public function PATCH_update()
    {
        $this->respond($this->service()->update($this->entityId(), $this->input()));
    }

    public function DELETE_remove()
    {
        $this->respond($this->service()->remove($this->entityId()));
    }

    private function service()
    {
        $this->requireAuthentication();

        return new FederativeEntityService(new FederativeEntityRepository(), function () {
            $user = App::i()->user;

            return !$user->is('guest') && $user->is('saasSuperAdmin');
        }, null, null, function (FederativeEntity $entity) {
            Plugin::scheduleParInformation($entity);
        });
    }

    /**
     * Árvore do PAR da oportunidade para a tela: só o cache. Sem cache, enfileira a busca e responde `pending`.
     */
    public function GET_parInformation()
    {
        $this->requireAuthentication();

        $opportunity = App::i()->repo('Opportunity')->find($this->opportunityId());

        if (!$opportunity) {
            $this->json(['available' => false, 'reason' => 'notFound', 'message' => 'Oportunidade não encontrada.', 'exercicios' => []], 404);

            return;
        }

        $opportunity->checkPermission('modify');

        $entity = Plugin::sealedEntity($opportunity);

        if ($entity === null) {
            $this->json(['available' => false, 'reason' => 'notFound', 'message' => 'A oportunidade não tem ente federado.', 'exercicios' => []], 404);

            return;
        }

        $result = Plugin::parInformationService()->cached($entity);

        // sem árvore (nunca buscada ou falha recente), a tela pede uma nova busca; o limite fica no Plugin
        if (in_array($result->getState(), [ParInformationResult::PENDING, ParInformationResult::UNAVAILABLE], true)) {
            Plugin::requestParFetch($entity);
            Plugin::ensureParSync();
        }

        $this->json($result->toArray());
    }

    /**
     * Histórico de envios da oportunidade, do mais novo ao mais antigo, 20 por página (`?page=`).
     */
    public function GET_logs()
    {
        $this->requireAuthentication();

        $service = new SendHistoryService(new SendLogRepository(), function ($opportunityId) {
            return Plugin::canViewSendHistory($opportunityId);
        });

        $result = $service->page($this->opportunityId(), isset($this->data['page']) ? $this->data['page'] : 1);

        $this->json($result->getBody(), $result->getStatus());
    }

    // o id vem como argumento da rota (posicional) ou como `id` nos dados
    private function opportunityId()
    {
        if (isset($this->data['id'])) {
            return (int) $this->data['id'];
        }

        $args = $this->getUrlData();

        return isset($args[0]) ? (int) $args[0] : 0;
    }

    private function respond(ApiResult $result)
    {
        // `json()` já usa o status como código HTTP; o erro segue o formato de `errorJson()`
        $this->json($result->getBody(), $result->getStatus());
    }

    private function entityId()
    {
        return isset($this->data['id']) ? (int) $this->data['id'] : 0;
    }

    /**
     * O Slim 2 só lê corpo form-urlencoded em `$this->data`; um corpo JSON é decodificado aqui.
     */
    private function input()
    {
        $request = App::i()->request;
        $data = $this->data;

        if (stripos((string) $request->getContentType(), 'application/json') === 0) {
            $decoded = json_decode((string) $request->getBody(), true);

            if (is_array($decoded)) {
                $data = $decoded + $data;
            }
        }

        return $data;
    }
}

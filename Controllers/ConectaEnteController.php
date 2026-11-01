<?php

namespace ConectaEnte\Controllers;

use ConectaEnte\Http\ApiResult;
use ConectaEnte\Repositories\FederativeEntityRepository;
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
        });
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

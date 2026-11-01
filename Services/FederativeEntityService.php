<?php

namespace ConectaEnte\Services;

use ConectaEnte\Entities\FederativeEntity;
use ConectaEnte\Exceptions\DuplicateSealException;
use ConectaEnte\Http\ApiResult;
use ConectaEnte\Http\Client;
use ConectaEnte\Repositories\FederativeEntityRepository;
use MapasCulturais\i;
use Respect\Validation\Rules\IntVal;
use Respect\Validation\Rules\NotEmpty;

class FederativeEntityService
{
    private $repository;
    private $verifyToken;
    private $canManage;
    private $newEntity;

    public function __construct(FederativeEntityRepository $repository, callable $canManage, callable $verifyToken = null, callable $newEntity = null)
    {
        $this->newEntity = $newEntity ?: function () {
            return new FederativeEntity();
        };
        $this->repository = $repository;
        $this->verifyToken = $verifyToken ?: function ($token) {
            return Client::fromConfig()->validateToken($token);
        };
        $this->canManage = $canManage;
    }

    public function all()
    {
        if (!$this->allowed()) {
            return $this->forbidden();
        }

        return ApiResult::ok(array_map([$this, 'present'], $this->repository->findAll()));
    }

    public function verifyToken(array $input)
    {
        if (!$this->allowed()) {
            return $this->forbidden();
        }

        $token = $this->text($input, 'token');
        if ($token === '') {
            return ApiResult::error(400, ['token' => $this->t('Informe o token do ente.')]);
        }

        $validation = call_user_func($this->verifyToken, $token);
        if (!$validation->isAccepted()) {
            return $this->tokenFailure($validation);
        }

        return ApiResult::ok(['name' => $validation->getEntityName(), 'cnpj' => $validation->getDocument()]);
    }

    public function create(array $input)
    {
        if (!$this->allowed()) {
            return $this->forbidden();
        }

        $name = $this->text($input, 'name');
        $token = $this->text($input, 'token');
        $sealId = isset($input['seal_id']) ? $input['seal_id'] : null;

        $errors = $this->requiredErrors($name, $token) + $this->sealErrors($sealId);
        if ($errors) {
            return ApiResult::error(400, $errors);
        }

        $conflict = $this->sealConflict($sealId, null) ?: $this->opportunityConflict($sealId, null);
        if ($conflict) {
            return $conflict;
        }

        $validation = call_user_func($this->verifyToken, $token);
        if (!$validation->isAccepted()) {
            return $this->tokenFailure($validation);
        }

        $ente = call_user_func($this->newEntity);
        $ente->setName($name);
        $ente->setSealId((int) $sealId);
        $ente->setToken($token);
        $ente->setCnpj($validation->getDocument());

        return $this->save($ente, 201);
    }

    public function update($id, array $input)
    {
        if (!$this->allowed()) {
            return $this->forbidden();
        }

        $ente = $this->repository->findById($id);
        if ($ente === null) {
            return $this->notFound();
        }

        $errors = [];

        if (array_key_exists('name', $input) && $this->text($input, 'name') === '') {
            $errors['name'] = $this->t('Informe o nome do ente.');
        }

        if (array_key_exists('token', $input) && $this->text($input, 'token') === '') {
            $errors['token'] = $this->t('Informe o token do ente.');
        }

        if (array_key_exists('seal_id', $input)) {
            $errors += $this->sealErrors($input['seal_id']);
        }

        if ($errors) {
            return ApiResult::error(400, $errors);
        }

        $changeSeal = array_key_exists('seal_id', $input) && (int) $input['seal_id'] !== $ente->getSealId();
        if ($changeSeal) {
            $conflict = $this->sealConflict($input['seal_id'], $ente->getId())
                ?: $this->opportunityConflict($input['seal_id'], $ente->getId());
            if ($conflict) {
                return $conflict;
            }
        }

        // o token só substitui o antigo depois de aceito; reenviar o mesmo token reverifica e renova o CNPJ
        $validation = null;
        if (array_key_exists('token', $input)) {
            $validation = call_user_func($this->verifyToken, $this->text($input, 'token'));
            if (!$validation->isAccepted()) {
                return $this->tokenFailure($validation);
            }
        }

        // a entidade é gerenciada pelo Doctrine: só muda depois de todas as checagens passarem
        if ($changeSeal) {
            $ente->setSealId((int) $input['seal_id']);
        }

        if (array_key_exists('name', $input)) {
            $ente->setName($this->text($input, 'name'));
        }

        if ($validation !== null) {
            $ente->setToken($this->text($input, 'token'));
            $ente->setCnpj($validation->getDocument());
        }

        return $this->save($ente, 200);
    }

    public function remove($id)
    {
        if (!$this->allowed()) {
            return $this->forbidden();
        }

        $ente = $this->repository->findById($id);
        if ($ente === null) {
            return $this->notFound();
        }

        $this->repository->delete($ente);

        return ApiResult::ok(['deleted' => true]);
    }

    /**
     * Lista de campos permitidos: o token nunca sai, só o indicador de que existe e, se for longo
     * o bastante para não revelar a maior parte dele, os 4 últimos caracteres.
     */
    public function present(FederativeEntity $ente)
    {
        $token = (string) $ente->getToken();

        return [
            'id' => $ente->getId(),
            'name' => $ente->getName(),
            'seal_id' => $ente->getSealId(),
            'cnpj' => $ente->getCnpj(),
            'token_registered' => $token !== '',
            'token_last4' => strlen($token) > 8 ? substr($token, -4) : null,
        ];
    }

    private function save(FederativeEntity $ente, $status)
    {
        try {
            $this->repository->save($ente);
        } catch (DuplicateSealException $e) {
            return ApiResult::error(409, ['seal_id' => $e->getMessage()]);
        }

        return ApiResult::ok($this->present($ente), $status);
    }

    private function sealConflict($sealId, $ownId)
    {
        $existing = $this->repository->findBySealId((int) $sealId);

        if ($existing === null || $existing->getId() === $ownId) {
            return null;
        }

        $e = new DuplicateSealException($sealId, $existing->getName());

        return ApiResult::error(409, ['seal_id' => $e->getMessage()]);
    }

    /**
     * Um selo comum já aplicado a oportunidades vira selo de ente ao ser registrado: se alguma delas já
     * tem o selo de outro ente, ficaria com dois (#44, que só vigia as aplicações novas).
     *
     * @return ApiResult|null 409 nomeando a oportunidade e o outro ente
     */
    private function opportunityConflict($sealId, $ownId)
    {
        $found = $this->repository->findOpportunityHoldingAnotherEntitySeal((int) $sealId, $ownId);

        if (!$found) {
            return null;
        }

        return ApiResult::error(409, ['seal_id' => sprintf(
            $this->t('Este selo já está aplicado à oportunidade %d, que já tem o selo do ente "%s". Uma oportunidade só pode ter o selo de um ente federado.'),
            $found['opportunity_id'],
            $found['entity_name']
        )]);
    }

    private function tokenFailure($validation)
    {
        // API fora do ar ou integração sem host não são culpa do token: 503, e nada é gravado
        return ApiResult::error($validation->isUnavailable() ? 503 : 400, ['token' => $validation->getMessage()]);
    }

    private function requiredErrors($name, $token)
    {
        $errors = [];

        if (!(new NotEmpty())->validate($name)) {
            $errors['name'] = $this->t('Informe o nome do ente.');
        }

        if (!(new NotEmpty())->validate($token)) {
            $errors['token'] = $this->t('Informe o token do ente.');
        }

        return $errors;
    }

    private function sealErrors($sealId)
    {
        if (!(new IntVal())->validate($sealId) || (int) $sealId < 1) {
            return ['seal_id' => $this->t('Informe o selo do ente.')];
        }

        if (!$this->repository->sealExists($sealId)) {
            return ['seal_id' => $this->t('O selo informado não existe.')];
        }

        return [];
    }

    private function text(array $input, $key)
    {
        $value = isset($input[$key]) && is_scalar($input[$key]) ? trim((string) $input[$key]) : '';

        if ($key !== 'name') {
            return $value;
        }

        // espaços repetidos no meio do nome são sempre erro de digitação; o token fica intacto
        $collapsed = preg_replace('/\s+/u', ' ', $value);

        // UTF-8 inválido faz o preg_replace devolver null: vale como vazio, e a validação responde 400 em vez de a gravação falhar com 500
        return $collapsed === null ? '' : $collapsed;
    }

    private function allowed()
    {
        return (bool) call_user_func($this->canManage);
    }

    private function forbidden()
    {
        return ApiResult::error(403, $this->t('Apenas o super administrador do SaaS pode gerenciar entes federados.'));
    }

    private function notFound()
    {
        return ApiResult::error(404, $this->t('Ente federado não encontrado.'));
    }

    private function t($text)
    {
        return class_exists(i::class) ? i::__($text) : $text;
    }
}

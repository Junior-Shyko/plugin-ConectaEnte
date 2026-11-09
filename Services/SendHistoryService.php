<?php

namespace ConectaEnte\Services;

use ConectaEnte\Entities\SendLog;
use ConectaEnte\Entities\SendLogAttempt;
use ConectaEnte\Http\ApiResult;
use ConectaEnte\Repositories\SendLogRepository;
use ConectaEnte\Vocabulary\SendOutcome;
use MapasCulturais\i;

/**
 * O que a tela do histórico recebe (#55). A permissão é decidida por quem chama: o serviço só pergunta.
 */
class SendHistoryService
{
    const PAGE_SIZE = 20;
    // acima disso o deslocamento estouraria o inteiro; nenhuma oportunidade chega perto
    const MAX_PAGE = 1000000;

    // cabeçalhos que carregam credencial ou sessão: não saem na resposta, nem nos históricos já gravados
    const HIDDEN_HEADERS = ['authorization', 'proxy-authorization', 'cookie', 'set-cookie', 'x-api-key', 'x-auth-token'];

    private $repository;
    private $canView;

    /**
     * @param callable $canView recebe o id da oportunidade e diz se o usuário pode ver o histórico dela
     */
    public function __construct(SendLogRepository $repository, callable $canView)
    {
        $this->repository = $repository;
        $this->canView = $canView;
    }

    public function page($opportunityId, $page)
    {
        $opportunityId = (int) $opportunityId;

        if (!call_user_func($this->canView, $opportunityId)) {
            return ApiResult::error(403, ['message' => $this->t('Você não tem permissão para ver o histórico de envios desta oportunidade.')]);
        }

        $page = min(self::MAX_PAGE, max(1, (int) $page));
        $logs = $this->repository->findPage($opportunityId, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE + 1);
        $hasMore = count($logs) > self::PAGE_SIZE;
        $logs = array_slice($logs, 0, self::PAGE_SIZE);
        $attempts = $this->repository->findAttempts($logs);

        return ApiResult::ok([
            'items' => array_map(function (SendLog $log) use ($attempts) {
                return $this->presentLog($log, isset($attempts[$log->getId()]) ? $attempts[$log->getId()] : []);
            }, $logs),
            'page' => $page,
            'hasMore' => $hasMore,
        ]);
    }

    /**
     * Lista fixa de campos, de propósito: coluna nova na entidade não vira resposta sem alguém decidir isso.
     * Não há cabeçalho de requisição para vazar (a #54 nem o guarda), e o token do ente nunca passa por aqui.
     */
    private function presentLog(SendLog $log, array $attempts)
    {
        return [
            'id' => $log->getId(),
            'requestUuid' => $log->getRequestUuid(),
            'status' => $log->getResult(),
            'statusLabel' => $this->label($log->getResult()),
            'createdAt' => $this->date($log->getCreateTimestamp()),
            'userId' => $log->getUserId(),
            'attempts' => array_map([$this, 'presentAttempt'], $attempts),
        ];
    }

    private function presentAttempt(SendLogAttempt $attempt)
    {
        return [
            'attempt' => $attempt->getAttempt(),
            'maxAttempts' => $attempt->getMaxAttempts(),
            'status' => $attempt->getResult(),
            'statusLabel' => $this->label($attempt->getResult()),
            'sentAt' => $this->date($attempt->getSentAt()),
            'endpoint' => $attempt->getEndpoint(),
            'method' => $attempt->getHttpMethod(),
            'httpStatus' => $attempt->getHttpStatus(),
            'durationMs' => $attempt->getDurationMs(),
            'requestId' => $attempt->getRequestId(),
            'parEditalId' => $attempt->getParEditalId(),
            'errorMessage' => $attempt->getErrorMessage(),
            'payload' => $attempt->getPayload(),
            'responseHeaders' => $this->visibleHeaders($attempt->getResponseHeaders()),
            'response' => $attempt->getResponse(),
        ];
    }

    private function visibleHeaders($headers)
    {
        if (!is_array($headers)) {
            return $headers;
        }

        $visible = array_diff_key($headers, array_flip(self::HIDDEN_HEADERS));

        return $visible === [] ? null : $visible;
    }

    private function label($status)
    {
        $label = SendOutcome::label($status);

        return $label !== null ? $label : (string) $status;
    }

    private function date(\DateTime $date = null)
    {
        return $date ? $date->format('c') : null;
    }

    private function t($text)
    {
        return class_exists(i::class) ? i::__($text) : $text;
    }
}

<?php

namespace ConectaEnte\Services;

use ConectaEnte\Http\ApiResult;
use ConectaEnte\Repositories\SendLogRepository;
use ConectaEnte\Vocabulary\SendOutcome;
use MapasCulturais\i;

/**
 * A situação do envio que a oportunidade mostra de si mesma (#56): a elegibilidade de agora (#48) e o último
 * envio registrado (#54). A permissão é decidida por quem chama: o serviço só pergunta.
 */
class SendStatusService
{
    // estados que não são desfecho de envio
    const HIDDEN = 'hidden';
    const INELIGIBLE = 'ineligible';
    const NOT_SENT = 'not_sent';

    private $eligibility;
    private $repository;
    private $canView;
    private $requirementMessages;

    /**
     * @param callable $canView             recebe o id da oportunidade e diz se o usuário pode ver a situação dela
     * @param callable $requirementMessages recebe a oportunidade e devolve as pendências do edital: chave => [mensagens]
     */
    public function __construct(SendEligibility $eligibility, SendLogRepository $repository, callable $canView, callable $requirementMessages)
    {
        $this->eligibility = $eligibility;
        $this->repository = $repository;
        $this->canView = $canView;
        $this->requirementMessages = $requirementMessages;
    }

    public function status($opportunity)
    {
        if (!call_user_func($this->canView, (int) $opportunity->id)) {
            return ApiResult::error(403, ['message' => $this->t('Você não tem permissão para ver a situação do envio desta oportunidade.')]);
        }

        $eligibility = $this->eligibility->check($opportunity);

        if (in_array($eligibility->getReason(), [EligibilityResult::NO_SEAL, EligibilityResult::SEAL_WITHOUT_ENTITY], true)) {
            return ApiResult::ok(['visible' => false, 'state' => self::HIDDEN]);
        }

        $last = $this->lastSend((int) $opportunity->id);

        if (!$eligibility->isEligible()) {
            return ApiResult::ok($this->body(self::INELIGIBLE, $this->t('Não enviada'), $this->reasonText($eligibility->getReason()), $this->missing($opportunity, $eligibility), $last));
        }

        if ($last === null) {
            return ApiResult::ok($this->body(self::NOT_SENT, $this->t('Ainda não enviada'), $this->t('Nenhum envio ao CultBR foi feito para esta oportunidade.'), [], null));
        }

        return ApiResult::ok($this->body($last['status'], $last['statusLabel'], $this->outcomeText($last), [], $last));
    }

    /**
     * Lista fixa de campos, de propósito: nada do envio além do necessário para a tela chega a ela. O histórico
     * (#55) é o lugar do payload e da resposta, e o token do ente nunca passa por aqui.
     */
    private function body($state, $label, $message, array $missing, array $last = null)
    {
        return [
            'visible' => true,
            'state' => $state,
            'label' => $label,
            'message' => $message,
            'missing' => $missing,
            'lastSend' => $last,
        ];
    }

    private function lastSend($opportunityId)
    {
        $logs = $this->repository->findPage($opportunityId, 0, 1);

        if ($logs === []) {
            return null;
        }

        $log = $logs[0];
        $attempts = $this->repository->findAttempts([$log]);
        $attempts = isset($attempts[$log->getId()]) ? $attempts[$log->getId()] : [];
        $attempt = $attempts === [] ? null : end($attempts);
        $created = $log->getCreateTimestamp();

        return [
            'status' => $log->getResult(),
            'statusLabel' => $this->outcomeLabel($log->getResult()),
            'at' => $created ? $created->format('c') : null,
            'httpStatus' => $attempt ? $attempt->getHttpStatus() : null,
            'errorMessage' => $attempt ? $attempt->getErrorMessage() : null,
            'parEditalId' => $attempt ? $attempt->getParEditalId() : null,
        ];
    }

    private function outcomeLabel($status)
    {
        $label = SendOutcome::label($status);

        return $label !== null ? $label : (string) $status;
    }

    private function outcomeText(array $last)
    {
        switch ($last['status']) {
            case SendOutcome::SUCCESS:
                return $this->t('O CultBR aceitou o edital.');
            case SendOutcome::SIMULATED:
                return $this->t('Envio simulado: nada saiu do Mapas Culturais e o CultBR não recebeu o edital.');
            case SendOutcome::PENDING:
                return $this->t('O envio está em andamento ou aguarda uma nova tentativa.');
            case SendOutcome::ABANDONED:
                // dois caminhos levam a «abandoned»: envio substituído por um mais novo, ou oportunidade/ente que mudou antes de ele terminar
                return $this->t('O envio foi descartado: foi substituído por um envio mais novo, ou a oportunidade ou o ente mudou antes de ele terminar.');
            case SendOutcome::REJECTED:
                return $this->t('O CultBR recusou o edital.');
            case SendOutcome::ERROR:
                // também cobre falha local imprevista na primeira tentativa: o motivo vem do erro da última tentativa
                return $this->t('O envio falhou.');
            default:
                return null;
        }
    }

    private function reasonText($reason)
    {
        $texts = [
            EligibilityResult::IS_PHASE => $this->t('Esta é uma fase: o envio é feito pela oportunidade principal.'),
            EligibilityResult::ARCHIVED_OR_TRASH => $this->t('A oportunidade está arquivada ou na lixeira.'),
            EligibilityResult::NOT_PUBLISHED => $this->t('A oportunidade ainda não foi publicada.'),
            EligibilityResult::MISSING_FIELDS => $this->t('Faltam dados obrigatórios para o envio:'),
        ];

        return isset($texts[$reason]) ? $texts[$reason] : null;
    }

    /**
     * Cada pendência com o texto que a publicação mostra («O campo «X» é obrigatório.»). Campo do contrato que
     * não é requisito do edital não tem texto próprio e aparece pelo nome que o CultBR espera.
     *
     * @return string[]
     */
    private function missing($opportunity, EligibilityResult $eligibility)
    {
        if ($eligibility->getReason() !== EligibilityResult::MISSING_FIELDS) {
            return [];
        }

        $messages = call_user_func($this->requirementMessages, $opportunity);

        return array_map(function ($key) use ($messages) {
            return isset($messages[$key]) ? implode(' ', (array) $messages[$key]) : (string) $key;
        }, $eligibility->getMissingFields());
    }

    private function t($text)
    {
        return class_exists(i::class) ? i::__($text) : $text;
    }
}

<?php

namespace ConectaEnte\Services;

use ConectaEnte\Database\SendLogSchema;
use ConectaEnte\Vocabulary\SendOutcome;
use Doctrine\DBAL\Connection;

/**
 * Histórico de envios (#54). Escreve por SQL e não pelo EntityManager: uma falha de gravação fecharia o
 * EntityManager do envio, e o histórico nunca pode alterar o envio. Por isso cada método engole \Throwable,
 * loga na aplicação e devolve false.
 */
class SendLogService
{
    const ACTION_SEND = 'send';
    // tamanhos das colunas par_edital_id e request_id (SendLogSchema)
    const PAR_EDITAL_ID_LENGTH = 64;
    const REQUEST_ID_LENGTH = 255;

    private $connection;
    private $logger;

    /**
     * @param callable|null $logger recebe a mensagem de erro
     */
    public function __construct(Connection $connection, $logger = null)
    {
        $this->connection = $connection;
        $this->logger = $logger;
    }

    public static function newUuid()
    {
        $bytes = random_bytes(16);
        // versão 4 e variante RFC 4122
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * Abre o envio sob o UUID, ou continua o que já existe (retentativa do mesmo job). Abrir um envio novo
     * encerra como `abandoned` os pendentes da oportunidade: um job substituído nunca chega a se encerrar.
     */
    public function startOrResume($uuid, $opportunityId, $userId = null)
    {
        return $this->guarded('abrir o envio', function () use ($uuid, $opportunityId, $userId) {
            // ON CONFLICT: a retentativa reabre o mesmo UUID sem duplicar. O usuário vem do enfileiramento e pode ter
            // sido apagado desde então: a FK recusaria o INSERT e o envio ficaria sem histórico
            $created = $this->connection->executeUpdate(
                'INSERT INTO ' . SendLogSchema::LOG_TABLE . ' (id, request_uuid, opportunity_id, action, status, user_id, create_timestamp)
                 VALUES (nextval(\'' . SendLogSchema::LOG_SEQUENCE . '\'), :uuid, CAST(:opportunity AS integer), :action, :status,
                    (SELECT id FROM usr WHERE id = CAST(:user AS integer)), now())
                 ON CONFLICT (request_uuid) DO NOTHING',
                [
                    'uuid' => $uuid,
                    'opportunity' => $opportunityId,
                    'action' => self::ACTION_SEND,
                    'status' => SendOutcome::PENDING,
                    'user' => $userId,
                ]
            );

            // só quem abre um envio novo substitui os pendentes: um job antigo que retoma o seu não derruba o mais novo
            if ($created > 0) {
                $this->abandonPending($opportunityId, $uuid);
            }
        });
    }

    /**
     * UPDATE condicional, sem ler antes: dois workers concorrentes não se sobrepõem.
     */
    public function abandonPending($opportunityId, $exceptUuid = null)
    {
        return $this->guarded('abandonar os envios pendentes', function () use ($opportunityId, $exceptUuid) {
            $this->connection->executeUpdate(
                'UPDATE ' . SendLogSchema::LOG_TABLE . ' SET status = :abandoned, update_timestamp = now()
                 WHERE opportunity_id = CAST(:opportunity AS integer) AND status = :pending AND request_uuid <> :except',
                [
                    'abandoned' => SendOutcome::ABANDONED,
                    'pending' => SendOutcome::PENDING,
                    'opportunity' => $opportunityId,
                    'except' => (string) $exceptUuid,
                ]
            );
        });
    }

    /**
     * Uma linha por tentativa. `pending` do relatório (retentativa agendada) é, para a tentativa em si, um `error`.
     * Sem troca com o CultBR e sem falha (inelegível) não há tentativa: o motivo fica só no log da aplicação.
     */
    public function recordAttempt($uuid, $attempt, $maxAttempts, SendReport $report)
    {
        $outcome = $report->getOutcome();

        if ($outcome === SendOutcome::ABANDONED) {
            return true;
        }

        return $this->guarded('registrar a tentativa', function () use ($uuid, $attempt, $maxAttempts, $report, $outcome) {
            $exchange = $report->getExchange();
            $status = $this->attemptStatus($outcome);
            $failed = !in_array($status, [SendOutcome::SUCCESS, SendOutcome::SIMULATED], true);
            $message = $failed ? ($report->getMessage() !== null ? $report->getMessage() : ($exchange ? $exchange->getTransportError() : null)) : null;

            $this->connection->executeUpdate(
                'INSERT INTO ' . SendLogSchema::ATTEMPT_TABLE . ' (id, log_id, attempt, max_attempts, endpoint, http_method, http_status,
                    payload, response, response_headers, par_edital_id, request_id, error_message, status, sent_at, duration_ms)
                 SELECT nextval(\'' . SendLogSchema::ATTEMPT_SEQUENCE . '\'), l.id, CAST(:attempt AS integer), CAST(:max AS integer), :endpoint, :method, CAST(:http_status AS integer),
                    CAST(:payload AS jsonb), CAST(:response AS jsonb), CAST(:headers AS jsonb), :par_edital_id, :request_id, :error, :status, now(), CAST(:duration AS integer)
                 FROM ' . SendLogSchema::LOG_TABLE . ' l WHERE l.request_uuid = :uuid',
                [
                    'uuid' => $uuid,
                    'attempt' => (int) $attempt,
                    'max' => (int) $maxAttempts,
                    'endpoint' => $exchange ? AttemptSanitizer::text($exchange->getEndpoint()) : null,
                    'method' => $exchange ? $exchange->getMethod() : null,
                    'http_status' => $exchange ? $exchange->getStatus() : null,
                    'payload' => $exchange ? AttemptSanitizer::payloadJson($exchange->getPayload()) : null,
                    'response' => $exchange ? AttemptSanitizer::json(AttemptSanitizer::response($exchange->getBody())) : null,
                    'headers' => $exchange ? AttemptSanitizer::json(AttemptSanitizer::headers($exchange->getHeaders())) : null,
                    'par_edital_id' => AttemptSanitizer::limited($report->getParEditalId(), self::PAR_EDITAL_ID_LENGTH),
                    'request_id' => AttemptSanitizer::limited($report->getRequestId(), self::REQUEST_ID_LENGTH),
                    'error' => $message !== null ? AttemptSanitizer::text($message) : null,
                    'status' => $status,
                    'duration' => $exchange ? $exchange->getDurationMs() : null,
                ]
            );
        });
    }

    /**
     * Só sai de `pending`: um envio já encerrado (ou abandonado por outro worker) não muda de desfecho.
     * Desfecho ausente ou desconhecido vira `error`, nunca sucesso.
     */
    public function finish($uuid, $outcome)
    {
        $outcome = in_array($outcome, SendOutcome::finals(), true) ? $outcome : SendOutcome::ERROR;

        return $this->guarded('encerrar o envio', function () use ($uuid, $outcome) {
            $this->connection->executeUpdate(
                'UPDATE ' . SendLogSchema::LOG_TABLE . ' SET status = :status, update_timestamp = now()
                 WHERE request_uuid = :uuid AND status = :pending',
                ['status' => $outcome, 'uuid' => $uuid, 'pending' => SendOutcome::PENDING]
            );
        });
    }

    private function attemptStatus($outcome)
    {
        return in_array($outcome, [SendOutcome::SUCCESS, SendOutcome::REJECTED, SendOutcome::SIMULATED], true) ? $outcome : SendOutcome::ERROR;
    }

    private function guarded($doing, callable $operation)
    {
        try {
            $operation();

            return true;
        } catch (\Throwable $e) {
            if ($this->logger) {
                call_user_func($this->logger, "[ConectaEnte] não foi possível {$doing} no histórico de envios: {$e->getMessage()}");
            }

            return false;
        }
    }
}

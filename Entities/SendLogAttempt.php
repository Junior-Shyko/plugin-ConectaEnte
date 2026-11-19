<?php

namespace ConectaEnte\Entities;

use Doctrine\ORM\Mapping as ORM;
use MapasCulturais\App;

/**
 * Uma tentativa de um envio: o que foi enviado, o que o CultBR respondeu e quanto levou.
 * Não guarda cabeçalho de requisição: é ele que carrega o token do ente.
 *
 * A propriedade é `result` sobre a coluna `status`: ver SendLog.
 *
 * @ORM\Table(name="conectaente_send_log_attempt")
 * @ORM\Entity
 * @ORM\entity(repositoryClass="MapasCulturais\Repository")
 */
class SendLogAttempt extends \MapasCulturais\Entity
{
    /**
     * @var integer
     *
     * @ORM\Column(name="id", type="integer", nullable=false)
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="SEQUENCE")
     * @ORM\SequenceGenerator(sequenceName="conectaente_send_log_attempt_id_seq", allocationSize=1, initialValue=1)
     */
    protected $id;

    /**
     * @var SendLog
     *
     * @ORM\ManyToOne(targetEntity="ConectaEnte\Entities\SendLog", inversedBy="attempts")
     * @ORM\JoinColumn(name="log_id", referencedColumnName="id", nullable=false, onDelete="CASCADE")
     */
    protected $log;

    /**
     * @var integer
     *
     * @ORM\Column(name="attempt", type="integer", nullable=false)
     */
    protected $attempt;

    /**
     * @var integer
     *
     * @ORM\Column(name="max_attempts", type="integer", nullable=false)
     */
    protected $maxAttempts;

    /**
     * @var string
     *
     * @ORM\Column(name="endpoint", type="text", nullable=true)
     */
    protected $endpoint;

    /**
     * @var string
     *
     * @ORM\Column(name="http_method", type="string", length=8, nullable=true)
     */
    protected $httpMethod;

    /**
     * @var integer
     *
     * @ORM\Column(name="http_status", type="integer", nullable=true)
     */
    protected $httpStatus;

    /**
     * @var array
     *
     * @ORM\Column(name="payload", type="json_array", nullable=true)
     */
    protected $payload;

    /**
     * Corpo JSON da resposta, ou {"raw": "..."} quando não era JSON ou foi truncado.
     *
     * @var array
     *
     * @ORM\Column(name="response", type="json_array", nullable=true)
     */
    protected $response;

    /**
     * @var array
     *
     * @ORM\Column(name="response_headers", type="json_array", nullable=true)
     */
    protected $responseHeaders;

    /**
     * @var string
     *
     * @ORM\Column(name="par_edital_id", type="string", length=64, nullable=true)
     */
    protected $parEditalId;

    /**
     * @var string
     *
     * @ORM\Column(name="request_id", type="string", length=255, nullable=true)
     */
    protected $requestId;

    /**
     * @var string
     *
     * @ORM\Column(name="error_message", type="text", nullable=true)
     */
    protected $errorMessage;

    /**
     * Um valor de SendOutcome: success, error, rejected ou simulated.
     *
     * @var string
     *
     * @ORM\Column(name="status", type="string", length=16, nullable=false)
     */
    protected $result;

    /**
     * @var \DateTime
     *
     * @ORM\Column(name="sent_at", type="datetime", nullable=false)
     */
    protected $sentAt;

    /**
     * @var integer
     *
     * @ORM\Column(name="duration_ms", type="integer", nullable=true)
     */
    protected $durationMs;

    public function getId()
    {
        return $this->id;
    }

    public function getLog()
    {
        return $this->log;
    }

    public function getAttempt()
    {
        return $this->attempt;
    }

    public function getMaxAttempts()
    {
        return $this->maxAttempts;
    }

    public function getEndpoint()
    {
        return $this->endpoint;
    }

    public function getHttpMethod()
    {
        return $this->httpMethod;
    }

    public function getHttpStatus()
    {
        return $this->httpStatus;
    }

    public function getPayload()
    {
        return $this->payload;
    }

    public function getResponse()
    {
        return $this->response;
    }

    public function getResponseHeaders()
    {
        return $this->responseHeaders;
    }

    public function getParEditalId()
    {
        return $this->parEditalId;
    }

    public function getRequestId()
    {
        return $this->requestId;
    }

    public function getErrorMessage()
    {
        return $this->errorMessage;
    }

    public function getResult()
    {
        return $this->result;
    }

    public function getSentAt()
    {
        return $this->sentAt;
    }

    public function getDurationMs()
    {
        return $this->durationMs;
    }

    public function canUser($action, $userOrAgent = null)
    {
        $app = App::i();

        if (!$app->isAccessControlEnabled()) {
            return true;
        }

        $user = $userOrAgent ?: $app->user;

        return $user && !$user->is('guest') && $user->is('saasSuperAdmin');
    }
}

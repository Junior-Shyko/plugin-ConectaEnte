<?php

namespace ConectaEnte\Entities;

use Doctrine\ORM\Mapping as ORM;
use MapasCulturais\App;

/**
 * Um envio de oportunidade ao CultBR, com N tentativas. Quem grava é o SendLogService, por SQL: falha no
 * histórico não pode fechar o EntityManager do envio. A entidade serve à leitura (#55).
 *
 * A propriedade é `result` sobre a coluna `status`: Entity::setStatus(int) e o MagicSetter interceptariam `status`.
 *
 * @ORM\Table(name="conectaente_send_log")
 * @ORM\Entity
 * @ORM\entity(repositoryClass="MapasCulturais\Repository")
 */
class SendLog extends \MapasCulturais\Entity
{
    /**
     * @var integer
     *
     * @ORM\Column(name="id", type="integer", nullable=false)
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="SEQUENCE")
     * @ORM\SequenceGenerator(sequenceName="conectaente_send_log_id_seq", allocationSize=1, initialValue=1)
     */
    protected $id;

    /**
     * @var string
     *
     * @ORM\Column(name="request_uuid", type="string", length=36, nullable=false)
     */
    protected $requestUuid;

    /**
     * Sem associação com Opportunity: o histórico sobrevive à exclusão dela.
     *
     * @var integer
     *
     * @ORM\Column(name="opportunity_id", type="integer", nullable=false)
     */
    protected $opportunityId;

    /**
     * @var string
     *
     * @ORM\Column(name="action", type="string", length=32, nullable=false)
     */
    protected $action;

    /**
     * Um valor de SendOutcome.
     *
     * @var string
     *
     * @ORM\Column(name="status", type="string", length=16, nullable=false)
     */
    protected $result;

    /**
     * @var integer
     *
     * @ORM\Column(name="user_id", type="integer", nullable=true)
     */
    protected $userId;

    /**
     * @var \DateTime
     *
     * @ORM\Column(name="create_timestamp", type="datetime", nullable=false)
     */
    protected $createTimestamp;

    /**
     * @var \DateTime
     *
     * @ORM\Column(name="update_timestamp", type="datetime", nullable=true)
     */
    protected $updateTimestamp;

    /**
     * @var \Doctrine\Common\Collections\Collection|SendLogAttempt[]
     *
     * @ORM\OneToMany(targetEntity="ConectaEnte\Entities\SendLogAttempt", mappedBy="log")
     * @ORM\OrderBy({"attempt" = "ASC", "id" = "ASC"})
     */
    protected $attempts;

    public function getId()
    {
        return $this->id;
    }

    public function getRequestUuid()
    {
        return $this->requestUuid;
    }

    public function getOpportunityId()
    {
        return $this->opportunityId;
    }

    public function getAction()
    {
        return $this->action;
    }

    public function getResult()
    {
        return $this->result;
    }

    public function getUserId()
    {
        return $this->userId;
    }

    public function getCreateTimestamp()
    {
        return $this->createTimestamp;
    }

    public function getUpdateTimestamp()
    {
        return $this->updateTimestamp;
    }

    public function getAttempts()
    {
        return $this->attempts;
    }

    // quem lê o histórico é o endpoint (permissão de edição da oportunidade); pela entidade, só o saasSuperAdmin
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

<?php

namespace ConectaEnte\Repositories;

use ConectaEnte\Entities\SendLog;
use ConectaEnte\Entities\SendLogAttempt;
use Doctrine\ORM\EntityManagerInterface;
use MapasCulturais\App;

/**
 * Leitura do histórico de envios (#54). Quem grava é o SendLogService.
 */
class SendLogRepository
{
    private $em;

    public function __construct(EntityManagerInterface $em = null)
    {
        $this->em = $em ?: App::i()->em;
    }

    /**
     * Do envio mais novo ao mais antigo. Pede-se `$size + 1` para saber se há próxima página sem um COUNT.
     *
     * @return SendLog[]
     */
    public function findPage($opportunityId, $offset, $limit)
    {
        return $this->em->createQueryBuilder()
            ->select('l')
            ->from(SendLog::class, 'l')
            ->where('l.opportunityId = :opportunity')
            ->orderBy('l.createTimestamp', 'DESC')
            ->addOrderBy('l.id', 'DESC')
            ->setFirstResult((int) $offset)
            ->setMaxResults((int) $limit)
            ->setParameter('opportunity', (int) $opportunityId)
            ->getQuery()
            ->getResult();
    }

    /**
     * As tentativas dos envios da página numa consulta só, agrupadas pelo id do envio.
     *
     * @param SendLog[] $logs
     * @return array<int, SendLogAttempt[]>
     */
    public function findAttempts(array $logs)
    {
        if ($logs === []) {
            return [];
        }

        $attempts = $this->em->createQueryBuilder()
            ->select('a')
            ->from(SendLogAttempt::class, 'a')
            ->where('a.log IN (:logs)')
            ->orderBy('a.attempt', 'ASC')
            ->addOrderBy('a.id', 'ASC')
            ->setParameter('logs', $logs)
            ->getQuery()
            ->getResult();

        $grouped = [];

        foreach ($attempts as $attempt) {
            $grouped[$attempt->getLog()->getId()][] = $attempt;
        }

        return $grouped;
    }

    public function hasAny($opportunityId)
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(l.id)')
            ->from(SendLog::class, 'l')
            ->where('l.opportunityId = :opportunity')
            ->setParameter('opportunity', (int) $opportunityId)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }
}

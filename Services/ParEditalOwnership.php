<?php

namespace ConectaEnte\Services;

use ConectaEnte\Metadata\CultBrMetadata;
use Doctrine\DBAL\Connection;

/**
 * Um id de edital do CultBR pertence a uma oportunidade só (índice único parcial em `opportunity_meta`).
 * A checagem prévia evita bater no índice: a violação fecharia o EntityManager do envio.
 */
class ParEditalOwnership
{
    private $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @return int|null id da outra oportunidade que já tem o `id_par_edital`; null se está livre
     */
    public function otherOwner($parEditalId, $opportunityId)
    {
        $owner = $this->connection->fetchColumn(
            'SELECT object_id FROM opportunity_meta WHERE key = :key AND value = :value AND object_id <> :opportunity ORDER BY object_id LIMIT 1',
            ['key' => CultBrMetadata::PAR_EDITAL_ID, 'value' => (string) $parEditalId, 'opportunity' => (int) $opportunityId]
        );

        return $owner === false || $owner === null ? null : (int) $owner;
    }
}

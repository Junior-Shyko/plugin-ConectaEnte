<?php

namespace ConectaEnte\Migrations;

use ConectaEnte\Metadata\CultBrMetadata;
use MapasCulturais\App;

/**
 * Deixa cada edital do CultBR com uma dona só, antes de o índice único existir: ele não nasce com
 * duplicata. Fica a oportunidade de menor id (a mais antiga); as outras perdem o metadado e o
 * próximo envio aceito o grava de novo.
 */
final class ClaimParEditalId
{
    public static function run()
    {
        return (int) App::i()->em->getConnection()->executeUpdate(
            'DELETE FROM opportunity_meta WHERE id IN (
                SELECT id FROM (
                    SELECT id, row_number() OVER (PARTITION BY value ORDER BY object_id, id) AS position
                    FROM opportunity_meta
                    WHERE key = ? AND value <> \'\'
                ) ranked WHERE position > 1
            )',
            [CultBrMetadata::PAR_EDITAL_ID]
        );
    }
}

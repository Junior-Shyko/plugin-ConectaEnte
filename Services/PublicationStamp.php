<?php

namespace ConectaEnte\Services;

use ConectaEnte\Metadata\CultBrMetadata as Meta;
use DateTime;
use MapasCulturais\Entities\Opportunity;

/**
 * Data de publicação do edital (`data_publicacao_edital` do payload).
 *
 * É um campo do formulário da aba CultBR. Quando está vazio e a oportunidade está ativa, o plugin o
 * preenche com a data do salvamento; depois disso o gestor pode alterá-lo, e o plugin não o sobrescreve.
 */
final class PublicationStamp
{
    const FORMAT = 'Y-m-d';

    /**
     * Chamado em `entity(Opportunity).save:before`, antes de o core gravar a entidade.
     */
    public function stamp(Opportunity $opportunity)
    {
        if ((int) $opportunity->status !== Opportunity::STATUS_ENABLED) {
            return;
        }

        if ($opportunity->getMetadata(Meta::PUBLICATION_DATE)) {
            return;
        }

        $opportunity->{Meta::PUBLICATION_DATE} = (new DateTime())->format(self::FORMAT);
    }

    /**
     * A data gravada, ou nulo quando não é uma data válida. Aceita o prefixo de hora de versões anteriores.
     */
    public static function parse($value)
    {
        if (!is_string($value) || strlen($value) < 10) {
            return null;
        }

        $date = substr($value, 0, 10);
        $parsed = DateTime::createFromFormat('!' . self::FORMAT, $date);

        // o PHP aceita 2026-02-30 e devolve 02/03; a data só vale se voltar igual
        return $parsed && $parsed->format(self::FORMAT) === $date ? $parsed : null;
    }
}

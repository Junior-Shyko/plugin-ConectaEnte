<?php

namespace ConectaEnte\Services;

use ConectaEnte\Metadata\CultBrMetadata as Meta;
use DateTime;
use MapasCulturais\Entities\Opportunity;

/**
 * Grava a data em que a oportunidade passou a publicada: é o `data_publicacao_edital` do payload.
 *
 * A v5 não tem essa data no core nem tema que a grave, então o plugin a grava no primeiro salvamento
 * com status ativado, e não a sobrescreve depois (uma despublicação e nova publicação mantém a data
 * original).
 */
final class PublicationStamp
{
    // com o offset do fuso: se o fuso padrão do PHP mudar, a data gravada continua apontando o mesmo instante
    const FORMAT = DATE_ATOM;

    /**
     * Chamado em `entity(Opportunity).save:before`, antes de o core gravar a entidade.
     */
    public function stamp(Opportunity $opportunity)
    {
        if ((int) $opportunity->status !== Opportunity::STATUS_ENABLED) {
            return;
        }

        if ($opportunity->getMetadata(Meta::PUBLISHED_AT)) {
            return;
        }

        $opportunity->{Meta::PUBLISHED_AT} = (new DateTime())->format(self::FORMAT);
    }
}

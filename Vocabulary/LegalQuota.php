<?php

namespace ConectaEnte\Vocabulary;

/**
 * Cotas legais da reserva de vagas e a ampla concorrência. O valor é o texto pt-br que vai ao
 * payload (`reserva_vagas_cotas[].label`), por isso não muda com o idioma da instalação.
 */
class LegalQuota extends AbstractVocabulary
{
    const BLACK_PEOPLE = 'Pessoas negras (pretas e pardas)';
    const INDIGENOUS_PEOPLE = 'Pessoas indígenas';
    const PEOPLE_WITH_DISABILITIES = 'Pessoas com deficiência';
    const OPEN_COMPETITION = 'Ampla concorrência';

    public static function legal()
    {
        return [self::BLACK_PEOPLE, self::INDIGENOUS_PEOPLE, self::PEOPLE_WITH_DISABILITIES];
    }
}

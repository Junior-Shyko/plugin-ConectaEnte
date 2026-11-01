<?php

namespace ConectaEnte\Vocabulary;

class AffirmativeActionGroup extends AbstractVocabulary
{
    const BLACK_PEOPLE = 'pessoas_negras';
    const INDIGENOUS_PEOPLE = 'pessoas_indigenas';
    const PEOPLE_WITH_DISABILITIES = 'pessoas_deficiencia';
    const WOMEN = 'mulheres';
    const TRADITIONAL_COMMUNITIES = 'povos_tradicionais';
    const LGBTQIAPN = 'lgbtqiapn';
    const ELDERLY_PEOPLE = 'pessoas_idosas';
    const HOMELESS_PEOPLE = 'situacao_rua';
    const OTHER_VULNERABLE_GROUPS = 'outros_vulnerabilizados';

    protected static function texts()
    {
        return [
            self::BLACK_PEOPLE => 'Pessoas negras',
            self::INDIGENOUS_PEOPLE => 'Pessoas indígenas',
            self::PEOPLE_WITH_DISABILITIES => 'Pessoas com deficiência',
            self::WOMEN => 'Mulheres',
            self::TRADITIONAL_COMMUNITIES => 'Povos e comunidades tradicionais',
            self::LGBTQIAPN => 'LGBTQIAPN+',
            self::ELDERLY_PEOPLE => 'Pessoas idosas',
            self::HOMELESS_PEOPLE => 'Pessoas em situação de rua',
            self::OTHER_VULNERABLE_GROUPS => 'Outros grupos vulnerabilizados socialmente',
        ];
    }
}

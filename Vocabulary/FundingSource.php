<?php

namespace ConectaEnte\Vocabulary;

class FundingSource extends AbstractVocabulary
{
    const OWN_RESOURCES = 'recursosProprios';
    const FEDERATIVE_AGREEMENTS = 'conveniosParcerias';
    const PARLIAMENTARY_AMENDMENTS = 'emendasParlamentares';
    const FIRST_CYCLE_REMAINDER = 'remanescentesCiclo1';
    const OTHER_SOURCES = 'outrasFontes';

    protected static function texts()
    {
        return [
            self::OWN_RESOURCES => 'Recursos próprios',
            self::FEDERATIVE_AGREEMENTS => 'Convênios/parcerias com entes federativos',
            self::PARLIAMENTARY_AMENDMENTS => 'Emendas parlamentares',
            self::FIRST_CYCLE_REMAINDER => 'Recursos remanescentes do ciclo 1',
            self::OTHER_SOURCES => 'Recursos de outras fontes',
        ];
    }
}

<?php

namespace ConectaEnte\Vocabulary;

class AffirmativeAction extends AbstractVocabulary
{
    const NOT_PLANNED = 'nao_previstas';
    const AGENT_BONUS = 'bonus_agentes';
    const THEME_BONUS = 'bonus_tematicas';
    const SPECIFIC_CATEGORY = 'categoria_especifica';
    const SPECIFIC_CALL = 'edital_especifico';
    const OTHER_LEGISLATION = 'outra_legislacao';

    public static function otherLegislationMaxLength()
    {
        // método e não constante: `cases()` lista toda constante como valor aceito
        return 140;
    }

    public static function hasGroups($value)
    {
        return in_array($value, [self::AGENT_BONUS, self::THEME_BONUS, self::SPECIFIC_CATEGORY, self::SPECIFIC_CALL], true);
    }

    /**
     * Se a modalidade exclui as demais.
     *
     * @param mixed $value
     *
     * @return bool
     */
    public static function isExclusive($value)
    {
        return $value === self::NOT_PLANNED;
    }

    protected static function texts()
    {
        return [
            self::NOT_PLANNED => 'Não são previstas outras ações afirmativas',
            self::AGENT_BONUS => 'Bônus de pontuação para agentes culturais',
            self::THEME_BONUS => 'Bônus de pontuação para projetos com temáticas específicas',
            self::SPECIFIC_CATEGORY => 'Categoria específica',
            self::SPECIFIC_CALL => 'Edital específico',
            self::OTHER_LEGISLATION => 'Outra ação afirmativa prevista em legislação local',
        ];
    }
}

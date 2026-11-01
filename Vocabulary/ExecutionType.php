<?php

namespace ConectaEnte\Vocabulary;

/**
 * Formas de execução (tipoDeEdital) do Pnab. Sem equivalente no core nem no SNIIC: se o
 * Ministério publicar lista normativa, ela substitui esta.
 */
class ExecutionType extends AbstractVocabulary
{
    const CULTURAL_EXECUTION = 'Execução cultural';
    const CULTURAL_SPACE_SUBSIDY = 'Subsídio a espaços culturais';
    const CULTURAL_GRANT = 'Bolsa cultural';
    const CULTURAL_AWARD = 'Premiação cultural';
    const TCC_CULTURE_POINTS = 'TCC Pontos de Cultura';
    const TCC_CULTURE_HUBS = 'TCC Pontões de Cultura';
    const CULTURA_VIVA_GRANT = 'Bolsa Cultura Viva';
    const CULTURA_VIVA_AWARD = 'Premiação Cultura Viva';
    const NATIONAL_CONTINUED_ACTIONS_PROGRAM = 'Programa Nacional de Ações Continuadas';
    const NATIONAL_CULTURAL_INFRASTRUCTURE_PROGRAM = 'Programa Nacional de Infraestrutura Cultural';
    const NATIONAL_MANAGER_TRAINING_PROGRAM = 'Programa Nacional de Formação para Gestores';
    const OTHER = 'Outros';
}

<?php

namespace ConectaEnte\Vocabulary;

/**
 * Status do core v5 no formato `{id, nome}` do contrato (ParEditalMapasStatus). Status sem rótulo
 * não tem tradução: o payload o marca como faltante, em vez de mandar `nome: null`.
 */
class OpportunityStatus extends AbstractVocabulary
{
    const ENABLED = 1;
    const DRAFT = 0;
    const PHASE = -1;
    const ARCHIVED = -2;
    const DISABLED = -9;
    const TRASH = -10;
    const APPEAL_PHASE = -20;

    /**
     * @param mixed $status valor do campo `status` da oportunidade
     *
     * @return array{id: int, nome: string}|null nulo quando o status não tem rótulo
     */
    public static function toPayload($status)
    {
        if (!is_numeric($status)) {
            return null;
        }

        $value = static::tryFrom((int) $status);

        if ($value === null || !isset(static::texts()[$value])) {
            return null;
        }

        return ['id' => $value, 'nome' => static::text($value)];
    }

    protected static function texts()
    {
        return [
            self::ENABLED => 'Ativado',
            self::DRAFT => 'Rascunho',
            self::PHASE => 'Fase',
            self::ARCHIVED => 'Arquivado',
            self::DISABLED => 'Desabilitado',
            self::TRASH => 'Lixeira',
            self::APPEAL_PHASE => 'Fase de recurso',
        ];
    }
}

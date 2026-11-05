<?php

namespace ConectaEnte\Vocabulary;

/**
 * Desfecho de um envio ao CultBR. Só `success` quer dizer que o CultBR aceitou o edital.
 */
class SendOutcome extends AbstractVocabulary
{
    // ainda vai tentar de novo (retentativa agendada)
    const PENDING = 'pending';
    const SUCCESS = 'success';
    // esgotou as tentativas em falha temporária, ou falha local não prevista
    const ERROR = 'error';
    // modo dev: nada saiu do plugin, e um envio simulado nunca é contado como sucesso
    const SIMULATED = 'simulated';
    // a oportunidade ou o ente deixou de existir, ou deixou de ser elegível entre o enfileiramento e a execução
    const ABANDONED = 'abandoned';
    // o CultBR recusou com 4xx: a resposta é definitiva e não é retentada
    const REJECTED = 'rejected';

    protected static function texts()
    {
        return [
            self::PENDING => 'Aguardando nova tentativa',
            self::SUCCESS => 'Enviado',
            self::ERROR => 'Falhou',
            self::SIMULATED => 'Simulado',
            self::ABANDONED => 'Cancelado',
            self::REJECTED => 'Recusado pelo CultBR',
        ];
    }
}

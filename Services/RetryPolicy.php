<?php

namespace ConectaEnte\Services;

use ConectaEnte\Http\SendResult;
use ConectaEnte\Vocabulary\SendOutcome;

/**
 * Classifica a resposta do CultBR por faixa de status: 5xx e falha de conexão são temporárias (retentam
 * até o limite); qualquer outra recusa é definitiva, porque a resposta de cliente não muda sozinha.
 */
final class RetryPolicy
{
    public static function outcome(SendResult $result, $attempt, $maxAttempts)
    {
        if ($result->isAccepted()) {
            return SendOutcome::SUCCESS;
        }

        if ($result->isUnreachable() || $result->getStatus() >= 500) {
            return $attempt < $maxAttempts ? SendOutcome::PENDING : SendOutcome::ERROR;
        }

        return SendOutcome::REJECTED;
    }
}

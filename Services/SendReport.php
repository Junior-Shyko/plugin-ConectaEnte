<?php

namespace ConectaEnte\Services;

use ConectaEnte\Http\Exchange;

/**
 * O que aconteceu em uma execução do envio: o desfecho e o que o CultBR devolveu, para correlacionar.
 */
final class SendReport
{
    private $outcome;
    private $message;
    private $parEditalId;
    private $requestId;
    private $exchange;

    public function __construct($outcome, $message = null, $parEditalId = null, $requestId = null, Exchange $exchange = null)
    {
        $this->outcome = $outcome;
        $this->message = $message;
        $this->parEditalId = $parEditalId;
        $this->requestId = $requestId;
        $this->exchange = $exchange;
    }

    public function getOutcome()
    {
        return $this->outcome;
    }

    public function getMessage()
    {
        return $this->message;
    }

    public function getParEditalId()
    {
        return $this->parEditalId;
    }

    public function getRequestId()
    {
        return $this->requestId;
    }

    // null quando nada foi trocado com o CultBR (inelegível, ou o modo dev antes de montar o payload)
    public function getExchange()
    {
        return $this->exchange;
    }
}

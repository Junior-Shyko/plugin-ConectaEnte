<?php

namespace ConectaEnte\Services;

/**
 * O que aconteceu em uma execução do envio: o desfecho e o que o CultBR devolveu, para correlacionar.
 */
final class SendReport
{
    private $outcome;
    private $message;
    private $parEditalId;
    private $requestId;

    public function __construct($outcome, $message = null, $parEditalId = null, $requestId = null)
    {
        $this->outcome = $outcome;
        $this->message = $message;
        $this->parEditalId = $parEditalId;
        $this->requestId = $requestId;
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
}

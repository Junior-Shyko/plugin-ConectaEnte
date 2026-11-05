<?php

namespace ConectaEnte\Http;

class SendResult
{
    private $accepted;
    private $unreachable;
    private $response;
    private $message;
    private $status;
    private $transportError;
    private $requestId;

    private function __construct($accepted, $unreachable, array $response = null, $message = null, $status = 0, $transportError = null, $requestId = null)
    {
        $this->accepted = $accepted;
        $this->unreachable = $unreachable;
        $this->response = $response;
        $this->message = $message;
        $this->status = $status;
        $this->transportError = $transportError;
        $this->requestId = $requestId;
    }

    public static function ok(array $response, $requestId = null)
    {
        return new self(true, false, $response, null, 200, null, $requestId);
    }

    public static function rejected($message, $status = 0)
    {
        return new self(false, false, null, $message, $status);
    }

    public static function unreachable($status = 0, $transportError = null)
    {
        return new self(false, true, null, null, $status, $transportError);
    }

    public function isAccepted()
    {
        return $this->accepted;
    }

    public function isUnreachable()
    {
        return $this->unreachable;
    }

    public function getResponse()
    {
        return $this->response;
    }

    public function getMessage()
    {
        return $this->message;
    }

    public function getStatus()
    {
        return $this->status;
    }

    public function getTransportError()
    {
        return $this->transportError;
    }

    // o `x-request-id` do CultBR: guardado no opportunity para correlacionar um envio com o log deles
    public function getRequestId()
    {
        return $this->requestId;
    }
}

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

    private function __construct($accepted, $unreachable, array $response = null, $message = null, $status = 0, $transportError = null)
    {
        $this->accepted = $accepted;
        $this->unreachable = $unreachable;
        $this->response = $response;
        $this->message = $message;
        $this->status = $status;
        $this->transportError = $transportError;
    }

    public static function ok(array $response)
    {
        return new self(true, false, $response);
    }

    public static function rejected($message)
    {
        return new self(false, false, null, $message);
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
}

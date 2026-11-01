<?php

namespace ConectaEnte\Http;

class ApiResult
{
    private $status;
    private $body;

    private function __construct($status, $body)
    {
        $this->status = $status;
        $this->body = $body;
    }

    public static function ok($body, $status = 200)
    {
        return new self($status, $body);
    }

    public static function error($status, $data)
    {
        return new self($status, ['error' => true, 'data' => $data]);
    }

    public function getStatus()
    {
        return $this->status;
    }

    public function getBody()
    {
        return $this->body;
    }

    public function isError()
    {
        return $this->status >= 400;
    }
}

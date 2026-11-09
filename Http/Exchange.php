<?php

namespace ConectaEnte\Http;

/**
 * O que foi enviado ao CultBR e o que voltou, para o histórico (#54).
 *
 * Só carrega a resposta: os cabeçalhos da requisição levam o token do ente e por isso nunca entram aqui.
 */
final class Exchange
{
    private $endpoint;
    private $method;
    private $payload;
    private $status;
    private $body;
    private $headers;
    private $durationMs;
    private $transportError;

    public function __construct($endpoint, $method, array $payload, $status = null, $body = '', array $headers = [], $durationMs = null, $transportError = null)
    {
        $this->endpoint = (string) $endpoint;
        $this->method = (string) $method;
        $this->payload = $payload;
        // 0 é "não chegou ao servidor" no Response: no histórico isso é ausência de status
        $this->status = $status ? (int) $status : null;
        $this->body = (string) $body;
        $this->headers = $headers;
        $this->durationMs = $durationMs === null ? null : (int) $durationMs;
        $this->transportError = $transportError;
    }

    // modo dev: o payload é montado e validado, mas nada sai do plugin
    public static function simulated($endpoint, $method, array $payload)
    {
        return new self($endpoint, $method, $payload);
    }

    public static function fromResponse($endpoint, $method, array $payload, Response $response)
    {
        return new self(
            $endpoint,
            $method,
            $payload,
            $response->getStatus(),
            $response->getBody(),
            $response->getHeaders(),
            $response->getDurationMs(),
            $response->getTransportError()
        );
    }

    public function getEndpoint()
    {
        return $this->endpoint;
    }

    public function getMethod()
    {
        return $this->method;
    }

    public function getPayload()
    {
        return $this->payload;
    }

    public function getStatus()
    {
        return $this->status;
    }

    public function getBody()
    {
        return $this->body;
    }

    public function getHeaders()
    {
        return $this->headers;
    }

    public function getDurationMs()
    {
        return $this->durationMs;
    }

    public function getTransportError()
    {
        return $this->transportError;
    }
}

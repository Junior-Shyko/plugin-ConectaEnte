<?php

namespace Tests\ConectaEnte\Doubles;

use ConectaEnte\Http\Response;
use ConectaEnte\Http\Transport\TransportInterface;

/**
 * Responde uma resposta por chamada, na ordem; para paginação. Guarda as URLs pedidas.
 */
class SequencedTransport implements TransportInterface
{
    public $requestedUrls = [];
    public $sentHeaders = [];

    private $responses;

    /** @param Response[] $responses */
    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public static function page(array $body, $status = 200)
    {
        return Response::received($status, json_encode($body));
    }

    public function get($url, array $headers = [])
    {
        $this->requestedUrls[] = $url;
        $this->sentHeaders[] = $headers;

        return array_shift($this->responses) ?: Response::failed('sem resposta preparada');
    }

    public function put($url, array $body, array $headers = [])
    {
        return Response::failed('put não usado aqui');
    }

    public function post($url, array $body, array $headers = [])
    {
        return Response::failed('post não usado aqui');
    }
}

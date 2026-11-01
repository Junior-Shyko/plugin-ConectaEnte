<?php

namespace ConectaEnte\Http\Transport;

use ConectaEnte\Http\Response;

interface TransportInterface
{
    public function get($url, array $headers = []);

    public function put($url, array $body, array $headers = []);

    public function post($url, array $body, array $headers = []);
}

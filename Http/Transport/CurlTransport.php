<?php

namespace ConectaEnte\Http\Transport;

use ConectaEnte\Http\Response;

class CurlTransport implements TransportInterface
{
    // qualquer leitura pode estar rodando com alguém esperando na tela (ex.: validar token)
    const INTERACTIVE_CONNECT_TIMEOUT = 5;
    const INTERACTIVE_TIMEOUT = 10;

    // escrever só acontece na fila: teto curto esgota as tentativas contra um CultBR que só demorou
    const QUEUED_CONNECT_TIMEOUT = 30;
    const QUEUED_TIMEOUT = 60;

    public function get($url, array $headers = [])
    {
        $curl = curl_init($url);

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::INTERACTIVE_CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::INTERACTIVE_TIMEOUT,
            CURLOPT_HTTPHEADER => $this->headerLines($headers),
        ]);

        return $this->execute($curl);
    }

    public function put($url, array $body, array $headers = [])
    {
        return $this->write('PUT', $url, $body, $headers);
    }

    public function post($url, array $body, array $headers = [])
    {
        return $this->write('POST', $url, $body, $headers);
    }

    private function write($verb, $url, array $body, array $headers)
    {
        $curl = curl_init($url);
        $headers['Content-Type'] = 'application/json';

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::QUEUED_CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::QUEUED_TIMEOUT,
            CURLOPT_CUSTOMREQUEST => $verb,
            CURLOPT_POSTFIELDS => JsonBody::encode($body),
            CURLOPT_HTTPHEADER => $this->headerLines($headers),
        ]);

        return $this->execute($curl);
    }

    private function headerLines(array $headers)
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }

        return $lines;
    }

    private function execute($curl)
    {
        $headers = [];
        curl_setopt($curl, CURLOPT_HEADERFUNCTION, function ($curl, $line) use (&$headers) {
            // linha de status = início de uma resposta (a final pode vir depois de um 100-continue ou de um proxy):
            // só os cabeçalhos da última contam, senão o x-request-id de uma intermediária vazaria para a final
            if (stripos($line, 'HTTP/') === 0) {
                $headers = [];
            }

            $parts = explode(':', $line, 2);

            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }

            return strlen($line);
        });

        $body = curl_exec($curl);
        $error = curl_error($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $duration = (int) round(curl_getinfo($curl, CURLINFO_TOTAL_TIME) * 1000);

        curl_close($curl);

        if ($body === false || $error) {
            return Response::failed($error ?: 'falha de transporte', $duration);
        }

        return Response::received($status, $body, $headers, $duration);
    }
}

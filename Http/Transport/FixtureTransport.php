<?php

namespace ConectaEnte\Http\Transport;

use ConectaEnte\Http\Response;
use MapasCulturais\App;

class FixtureTransport implements TransportInterface
{
    private $directory;

    public function __construct($directory)
    {
        $this->directory = $directory;
    }

    public function get($url, array $headers = [])
    {
        $route = $this->routeOf($url);
        $file = "{$this->directory}/{$route}.json";

        if (!is_readable($file)) {
            App::i()->log->warning("ConectaEnte em modo dev: sem fixture para a rota {$route} em {$file}");

            return Response::failed("sem fixture para a rota {$route}");
        }

        return Response::received(200, (string) file_get_contents($file));
    }

    /**
     * O envio real nunca deveria chamar o transporte em modo dev — quem decide isso é o
     * serviço de envio (fase 2), que grava o desfecho `simulated` sem passar por aqui.
     */
    public function put($url, array $body, array $headers = [])
    {
        return $this->refuseWriting('put', $url);
    }

    public function post($url, array $body, array $headers = [])
    {
        return $this->refuseWriting('post', $url);
    }

    private function refuseWriting($verb, $url)
    {
        App::i()->log->warning("ConectaEnte em modo dev: {$verb}() não deveria ser chamado, rota {$this->routeOf($url)}");

        return Response::failed('envio real não roda em modo simulado');
    }

    private function routeOf($url)
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');

        return basename($path) !== '' ? basename($path) : 'index';
    }
}

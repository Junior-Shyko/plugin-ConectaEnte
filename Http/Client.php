<?php

namespace ConectaEnte\Http;

use ConectaEnte\Config;
use ConectaEnte\Dto\ParInformation;
use ConectaEnte\Http\Transport\CurlTransport;
use ConectaEnte\Http\Transport\FixtureTransport;
use ConectaEnte\Http\Transport\TransportInterface;
use MapasCulturais\i;

class Client
{
    const TYPE_SYSTEM = 'SISTEMA';
    const WRONG_TYPE_MESSAGE = 'Este token é válido, mas não é um token de sistema (ente).';
    const PAR_PAGE_SIZE = 100;
    const PAR_MAX_PAGES = 50;

    private $host;
    private $transport;

    public function __construct($host, TransportInterface $transport = null)
    {
        $this->host = $host;
        $this->transport = $transport ?: new CurlTransport();
    }

    public static function fromConfig()
    {
        $transport = Config::isLive() ? null : new FixtureTransport(__DIR__ . '/../fixtures');

        return new self(Config::host(), $transport);
    }

    public function validateToken($token)
    {
        // quebra de linha colada junto do token é comum e a API o recusaria como "inválido"
        $token = trim((string) $token);

        if ($token === '') {
            return TokenValidation::reject($this->translate('Informe o token do ente.'));
        }

        // o token vira linha de cabeçalho HTTP: controle no meio dele permitiria injetar cabeçalho
        if (preg_match('/[\x00-\x1f\x7f]/', $token)) {
            return TokenValidation::reject($this->translate('O token contém caracteres inválidos.'));
        }

        // sem host em modo live a URL sairia relativa e o cURL falharia como "serviço indisponível"
        if ($this->host === '' && !$this->transport instanceof FixtureTransport) {
            return TokenValidation::misconfigured();
        }

        $response = $this->transport->get($this->url('/api/v1/integracao/validar-token'), ['token' => $token]);

        if (!$response->reachedServer()) {
            return TokenValidation::unreachable();
        }

        $body = $response->json();

        if ($response->getStatus() === 200) {
            // 200 sem JSON é página de proxy/balanceador, não resposta do CultBR: não é culpa do token
            return $response->decoded() === null ? TokenValidation::unreachable() : $this->readValidation($body);
        }

        // 404 (host ou caminho errado), 408 e 429 não dizem nada sobre o token: é a API, não o usuário
        if ($response->getStatus() >= 500 || in_array($response->getStatus(), [404, 408, 429], true)) {
            return TokenValidation::unreachable();
        }

        // 403 é o token válido de outro tipo (um PAT): mensagem fixa, distinta das 4 recusas do 401
        if ($response->getStatus() === 403) {
            return TokenValidation::reject($this->translate(self::WRONG_TYPE_MESSAGE));
        }

        return TokenValidation::reject($this->readDetail($body, $response->getStatus(), $this->translate('A Plataforma CultBR recusou a verificação do token (HTTP %d).')));
    }

    public function health()
    {
        return $this->transport->get($this->url('/health'))->getStatus() === 200;
    }

    /**
     * Árvore do PAR do ente, casada pelo CNPJ. A API pagina e pode devolver mais de um ente para o
     * mesmo token: só o do CNPJ informado é usado, e os outros CNPJs voltam no resultado para o log.
     */
    public function getParInformation($token, $cnpj)
    {
        $simulated = $this->transport instanceof FixtureTransport;
        $wanted = preg_replace('/\D/', '', (string) $cnpj);
        $entities = [];
        $skip = 0;
        $complete = false;

        for ($page = 0; $page < self::PAR_MAX_PAGES; $page++) {
            $url = $this->url('/api/v1/par-information') . '?' . http_build_query(['skip' => $skip, 'limit' => self::PAR_PAGE_SIZE]);
            $response = $this->transport->get($url, ['token' => $token]);

            if (!$response->reachedServer()) {
                return ParInformationResult::unavailable($this->translate('A Plataforma CultBR não respondeu à consulta do PAR.'), $simulated);
            }

            if ($response->getStatus() !== 200) {
                return ParInformationResult::unavailable($this->readDetail($response->json(), $response->getStatus(), $this->translate('A Plataforma CultBR recusou a consulta do PAR (HTTP %d).')), $simulated);
            }

            $body = $response->decoded();

            // corpo sem `data` é página de proxy ou contrato diferente: não vira árvore vazia, que pareceria "sem PAR"
            if ($body === null || !isset($body['data']) || !is_array($body['data'])) {
                return ParInformationResult::unavailable($this->translate('A Plataforma CultBR devolveu a consulta do PAR em formato inesperado.'), $simulated);
            }

            $entities = array_merge($entities, array_filter($body['data'], 'is_array'));

            $hasNext = isset($body['pagination']['next']) && $body['pagination']['next'] !== null;

            if (!$hasNext || $body['data'] === []) {
                $complete = true;
                break;
            }

            $skip += self::PAR_PAGE_SIZE;
        }

        if (!$complete) {
            return ParInformationResult::unavailable($this->translate('A consulta do PAR tem mais páginas do que o limite; a árvore ficou incompleta.'), $simulated);
        }

        $match = null;
        $others = [];

        foreach ($entities as $raw) {
            $tree = ParInformation::fromArray($raw);

            if ($tree === null) {
                continue;
            }

            if ($match === null && $tree->cnpj === $wanted) {
                $match = $tree;
            } else {
                $others[] = $tree->cnpj;
            }
        }

        if ($match === null) {
            return ParInformationResult::notFound($this->translate('A Plataforma CultBR não tem árvore do PAR para o CNPJ deste ente.'), $simulated, $others);
        }

        return ParInformationResult::ok($match, $simulated, $others);
    }

    /**
     * Envia o edital selado; 5xx e falha de conexão são a única faixa retentável (retentativa
     * fica para a #49).
     */
    public function sendOpportunity($token, $idMapa, array $payload)
    {
        $response = $this->transport->put($this->sendOpportunityUrl($idMapa), $payload, ['token' => $token]);

        if (!$response->reachedServer() || $response->getStatus() >= 500) {
            return SendResult::unreachable($response->getStatus(), $response->getTransportError());
        }

        // nomes de cabeçalho HTTP não diferenciam maiúsculas: não depende do transport normalizar
        $headers = array_change_key_case($response->getHeaders(), CASE_LOWER);
        $requestId = isset($headers['x-request-id']) ? $headers['x-request-id'] : null;

        if ($response->getStatus() === 200) {
            $decoded = $response->decoded();

            // 200 sem JSON é página de proxy/balanceador, não aceite do CultBR: retenta como indisponível
            return $decoded === null
                ? SendResult::unreachable(200, 'resposta 200 sem JSON')
                : SendResult::ok($decoded, $requestId);
        }

        return SendResult::rejected($this->readDetail($response->json(), $response->getStatus(), $this->translate('A Plataforma CultBR recusou o envio do edital (HTTP %d).')), $response->getStatus());
    }

    public function sendOpportunityUrl($idMapa)
    {
        return $this->url('/api/v1/oportunidades/' . $idMapa);
    }

    private function readValidation(array $body)
    {
        // `valido` vem antes do tipo: a mensagem do tipo errado afirma que o token é válido
        if (empty($body['valido'])) {
            return TokenValidation::reject($this->translate('A Plataforma CultBR não reconheceu este token.'));
        }

        if (!isset($body['tipo']) || $body['tipo'] !== self::TYPE_SYSTEM) {
            return TokenValidation::reject($this->translate(self::WRONG_TYPE_MESSAGE));
        }

        if (empty($body['cnpj'])) {
            return TokenValidation::reject($this->translate('A Plataforma CultBR não reconheceu este token.'));
        }

        if (!preg_match('/^\d{14}\z/', (string) $body['cnpj'])) {
            return TokenValidation::reject($this->translate('A Plataforma CultBR devolveu um CNPJ inválido para este token.'));
        }

        // o contrato declara `nome_ente`; a resposta real observada em homologação usa `nome_entidade`
        $name = isset($body['nome_ente']) ? $body['nome_ente'] : (isset($body['nome_entidade']) ? $body['nome_entidade'] : null);

        return TokenValidation::accept((string) $body['cnpj'], $name !== null ? (string) $name : null);
    }

    /**
     * `detail` é texto em 400, 401, 403, 404 e 500 (quando JSON), e lista de erros de campo em 422.
     */
    private function readDetail(array $body, $status, $refusal)
    {
        $detail = isset($body['detail']) ? $body['detail'] : null;

        if (is_string($detail) && $detail !== '') {
            return $detail;
        }

        if (is_array($detail)) {
            $messages = array_filter(array_map([$this, 'fieldError'], $detail));

            if ($messages) {
                return implode('; ', $messages);
            }
        }

        return sprintf($refusal, $status);
    }

    // o formato de `detail` é do CultBR: item fora dele não pode derrubar a leitura de nenhum dos fluxos
    private function fieldError($error)
    {
        $message = is_array($error) && isset($error['msg']) ? $error['msg'] : null;

        if (!is_string($message) || $message === '') {
            return null;
        }

        $field = $this->fieldName(isset($error['loc']) ? $error['loc'] : null);

        return $field ? "{$field}: {$message}" : $message;
    }

    // só erro no corpo é campo do edital: `header` é a credencial e `query` é a rota, e nomeá-los confundiria
    private function fieldName($loc)
    {
        if (!is_array($loc) || !isset($loc[0]) || $loc[0] !== 'body') {
            return null;
        }

        $path = array_slice($loc, 1);

        return $path ? implode('.', $path) : null;
    }

    private function translate($text)
    {
        return class_exists(i::class) ? i::__($text) : $text;
    }

    /**
     * O contrato não declara `servers`: os caminhos dele são absolutos a partir da origem,
     * e `/health` fica fora de `/api/v1`. Por isso o host configurado é só a origem.
     */
    private function url($path)
    {
        return rtrim($this->host, '/') . $path;
    }
}

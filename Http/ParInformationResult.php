<?php

namespace ConectaEnte\Http;

use ConectaEnte\Dto\ParInformation;

/**
 * O resultado de ler a árvore do PAR de um ente. Um estado só, nunca lista vazia no lugar de falha:
 * - ok: a árvore do CNPJ do ente;
 * - notFound: a API respondeu, mas não há árvore para o CNPJ do ente;
 * - unavailable: a API não respondeu bem (não é cacheado);
 * - pending: ainda não há nada no cache para este ente.
 */
class ParInformationResult
{
    const OK = 'ok';
    const NOT_FOUND = 'notFound';
    const UNAVAILABLE = 'unavailable';
    const PENDING = 'pending';

    private $state;
    private $tree;
    private $message;
    private $simulated;
    private $otherCnpjs;

    private function __construct($state, $tree, $message, $simulated, array $otherCnpjs)
    {
        $this->state = $state;
        $this->tree = $tree;
        $this->message = $message;
        $this->simulated = $simulated;
        $this->otherCnpjs = $otherCnpjs;
    }

    public static function ok(ParInformation $tree, $simulated = false, array $otherCnpjs = [])
    {
        return new self(self::OK, $tree, null, $simulated, $otherCnpjs);
    }

    public static function notFound($message, $simulated = false, array $otherCnpjs = [])
    {
        return new self(self::NOT_FOUND, null, $message, $simulated, $otherCnpjs);
    }

    public static function unavailable($message, $simulated = false)
    {
        return new self(self::UNAVAILABLE, null, $message, $simulated, []);
    }

    public static function pending()
    {
        return new self(self::PENDING, null, 'As opções do PAR ainda não foram carregadas.', false, []);
    }

    public function getState()
    {
        return $this->state;
    }

    /** Árvore do ente, só quando o estado é ok. */
    public function getTree()
    {
        return $this->tree;
    }

    public function getMessage()
    {
        return $this->message;
    }

    public function isSimulated()
    {
        return $this->simulated;
    }

    /** CNPJs de outros entes que a API devolveu junto; o serviço os registra no log. */
    public function getOtherCnpjs()
    {
        return $this->otherCnpjs;
    }

    /** Cacheável: uma resposta definitiva (com árvore ou sem árvore para o CNPJ). */
    public function isDefinitive()
    {
        return $this->state === self::OK || $this->state === self::NOT_FOUND;
    }

    /** Formato da rota da tela: `available`, `simulated`, `exercicios` e, quando não há árvore, o motivo. */
    public function toArray()
    {
        $available = $this->state === self::OK;

        return [
            'available' => $available,
            'simulated' => $this->simulated,
            'reason' => $available ? null : $this->state,
            'message' => $available ? null : $this->message,
            'exercicios' => $available ? $this->tree->toArray() : [],
        ];
    }
}

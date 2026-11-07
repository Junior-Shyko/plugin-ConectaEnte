<?php

namespace ConectaEnte\Dto;

/**
 * A árvore do PAR de um ente: exercício → meta → ação → atividade, já casada com o CNPJ do ente.
 */
class ParInformation
{
    use ParNode;

    public $cnpj;
    public $name;
    /** @var ParExercise[] */
    public $exercises;

    public function __construct($cnpj, $name, array $exercises)
    {
        $this->cnpj = $cnpj;
        $this->name = $name;
        $this->exercises = $exercises;
    }

    /** Lê um item de `data` da API; nulo quando o item não traz CNPJ. */
    public static function fromArray(array $raw)
    {
        $cnpj = isset($raw['cnpj']) && is_scalar($raw['cnpj']) ? preg_replace('/\D/', '', (string) $raw['cnpj']) : '';

        if ($cnpj === '') {
            return null;
        }

        $name = isset($raw['nome']) && is_scalar($raw['nome']) ? (string) $raw['nome'] : null;
        return new self($cnpj, $name, self::nodes(ParExercise::class, $raw, 'exercicios'));
    }

    /** Nome do ente para a tela; sem nome, o CNPJ, que identifica o ente. */
    public function label()
    {
        return $this->name !== null && $this->name !== '' ? $this->name : $this->cnpj;
    }

    public function toArray()
    {
        return array_map(function ($exercise) {
            return $exercise->toArray();
        }, $this->exercises);
    }
}

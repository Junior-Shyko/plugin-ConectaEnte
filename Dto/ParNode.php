<?php

namespace ConectaEnte\Dto;

/**
 * Trecho comum dos nós da árvore do PAR: só o `id` é obrigatório no contrato, então o nome pode faltar.
 */
trait ParNode
{
    /** Nome para a tela; sem nome, o id, para o nó continuar identificável. */
    public function label()
    {
        return $this->name !== null && $this->name !== '' ? $this->name : 'Id ' . $this->id;
    }

    /** Nó sem `id` não pode ser escolhido nem enviado: é descartado na leitura. */
    protected static function idOf(array $raw)
    {
        return isset($raw['id']) && is_scalar($raw['id']) && (string) $raw['id'] !== '' ? (string) $raw['id'] : null;
    }

    protected static function nameOf(array $raw)
    {
        return isset($raw['nome']) && is_scalar($raw['nome']) ? (string) $raw['nome'] : null;
    }

    protected static function valueOf(array $raw)
    {
        return isset($raw['valor']) && is_scalar($raw['valor']) ? (string) $raw['valor'] : null;
    }

    /** Filhos sem id são descartados, como o próprio nó sem id. */
    public static function nodes($class, array $raw, $key)
    {
        $nodes = [];

        foreach (self::listOf($raw, $key) as $child) {
            $node = $class::fromArray($child);

            if ($node !== null) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    protected static function listOf(array $raw, $key)
    {
        return isset($raw[$key]) && is_array($raw[$key]) ? array_filter($raw[$key], 'is_array') : [];
    }
}

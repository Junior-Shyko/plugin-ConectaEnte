<?php

namespace ConectaEnte\Dto;

class ParAction
{
    use ParNode;

    public $id;
    public $name;
    public $value;
    /** @var ParActivity[] */
    public $activities;

    public function __construct($id, $name, $value, array $activities)
    {
        $this->id = $id;
        $this->name = $name;
        $this->value = $value;
        $this->activities = $activities;
    }

    public static function fromArray(array $raw)
    {
        $id = self::idOf($raw);

        if ($id === null) {
            return null;
        }

        return new self($id, self::nameOf($raw), self::valueOf($raw), self::nodes(ParActivity::class, $raw, 'atividades'));
    }

    public function toArray()
    {
        return ['id' => $this->id, 'nome' => $this->name, 'valor' => $this->value, 'atividades' => array_map(function ($node) {
            return $node->toArray();
        }, $this->activities)];
    }
}

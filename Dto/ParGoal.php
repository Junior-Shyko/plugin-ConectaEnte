<?php

namespace ConectaEnte\Dto;

class ParGoal
{
    use ParNode;

    public $id;
    public $name;
    public $value;
    /** @var ParAction[] */
    public $actions;

    public function __construct($id, $name, $value, array $actions)
    {
        $this->id = $id;
        $this->name = $name;
        $this->value = $value;
        $this->actions = $actions;
    }

    public static function fromArray(array $raw)
    {
        $id = self::idOf($raw);

        if ($id === null) {
            return null;
        }

        return new self($id, self::nameOf($raw), self::valueOf($raw), self::nodes(ParAction::class, $raw, 'acoes'));
    }

    public function toArray()
    {
        return ['id' => $this->id, 'nome' => $this->name, 'valor' => $this->value, 'acoes' => array_map(function ($node) {
            return $node->toArray();
        }, $this->actions)];
    }
}

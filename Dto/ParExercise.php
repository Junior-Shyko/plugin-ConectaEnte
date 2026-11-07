<?php

namespace ConectaEnte\Dto;

class ParExercise
{
    use ParNode;

    public $id;
    /** O ano é o nome do exercício na tela. */
    public $name;
    /** @var ParGoal[] */
    public $goals;

    public function __construct($id, $name, array $goals)
    {
        $this->id = $id;
        $this->name = $name;
        $this->goals = $goals;
    }

    public static function fromArray(array $raw)
    {
        $id = self::idOf($raw);

        if ($id === null) {
            return null;
        }

        $year = isset($raw['ano']) && is_scalar($raw['ano']) ? (string) $raw['ano'] : null;

        return new self($id, $year, self::nodes(ParGoal::class, $raw, 'metas'));
    }

    public function toArray()
    {
        return ['id' => $this->id, 'ano' => $this->name, 'metas' => array_map(function ($node) {
            return $node->toArray();
        }, $this->goals)];
    }
}

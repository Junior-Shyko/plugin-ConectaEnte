<?php

namespace ConectaEnte\Dto;

class ParActivity
{
    use ParNode;

    public $id;
    public $name;
    public $value;

    public function __construct($id, $name, $value)
    {
        $this->id = $id;
        $this->name = $name;
        $this->value = $value;
    }

    public static function fromArray(array $raw)
    {
        $id = self::idOf($raw);

        return $id === null ? null : new self($id, self::nameOf($raw), self::valueOf($raw));
    }

    public function toArray()
    {
        return ['id' => $this->id, 'nome' => $this->name, 'valor' => $this->value];
    }
}

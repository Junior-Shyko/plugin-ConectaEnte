<?php

namespace Tests\ConectaEnte\Doubles;

/**
 * Oportunidade mínima para a elegibilidade: a entidade real precisa do banco para ser criada.
 */
class FakeOpportunity
{
    public $id = 7;
    public $parent = null;
    public $status = 1;

    private $relations;

    public function __construct($status = 1, array $relations = [], $parent = null)
    {
        $this->status = $status;
        $this->relations = $relations;
        $this->parent = $parent;
    }

    public function getSealRelations()
    {
        return $this->relations;
    }
}

<?php

namespace Tests\ConectaEnte\Doubles;

/**
 * Cache em memória com a mesma assinatura que o serviço usa do CacheProvider do Doctrine.
 */
class ArrayCache
{
    public $saved = [];
    public $deleted = [];

    public function contains($id)
    {
        return array_key_exists($id, $this->saved);
    }

    public function fetch($id)
    {
        return $this->saved[$id];
    }

    public function save($id, $data, $lifeTime = 0)
    {
        $this->saved[$id] = $data;

        return true;
    }

    public function delete($id)
    {
        $this->deleted[] = $id;
        unset($this->saved[$id]);

        return true;
    }
}

<?php

namespace ConectaEnte\Services;

use ConectaEnte\Entities\FederativeEntity;

/**
 * Resultado de `SendEligibility::check()`: elegível ou não, com o motivo nomeado e os campos faltantes.
 */
final class EligibilityResult
{
    const NO_SEAL = 'no_seal';
    const SEAL_WITHOUT_ENTITY = 'seal_without_entity';
    const NOT_PUBLISHED = 'not_published';
    const ARCHIVED_OR_TRASH = 'archived_or_trash';
    const IS_PHASE = 'is_phase';
    const MISSING_FIELDS = 'missing_fields';

    private $reason;
    private $entity;
    private $missingFields;

    private function __construct($reason, FederativeEntity $entity = null, array $missingFields = [])
    {
        $this->reason = $reason;
        $this->entity = $entity;
        $this->missingFields = $missingFields;
    }

    public static function eligible(FederativeEntity $entity)
    {
        return new self(null, $entity);
    }

    public static function ineligible($reason, array $missingFields = [])
    {
        return new self($reason, null, $missingFields);
    }

    public function isEligible()
    {
        return $this->reason === null;
    }

    public function getReason()
    {
        return $this->reason;
    }

    /**
     * O ente que vai receber o edital; nulo quando não é elegível.
     */
    public function getEntity()
    {
        return $this->entity;
    }

    /**
     * Chaves do que falta (campos do contrato ou requisitos do edital); vazio se não for de campos.
     *
     * @return string[]
     */
    public function getMissingFields()
    {
        return $this->missingFields;
    }
}

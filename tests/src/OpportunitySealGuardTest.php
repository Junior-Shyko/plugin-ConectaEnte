<?php

namespace Tests\ConectaEnte;

use ConectaEnte\Entities\FederativeEntity;
use ConectaEnte\Repositories\FederativeEntityRepository;
use ConectaEnte\Services\OpportunitySealGuard;
use MapasCulturais\Entities\Seal;
use MapasCulturais\Entities\SealRelation;
use PHPUnit\Framework\TestCase;

class OpportunitySealGuardTest extends TestCase
{
    private $entities = [];

    protected function setUp(): void
    {
        $this->entities = [
            10 => $this->ente(1, 'Governo A', 10),
            20 => $this->ente(2, 'Governo B', 20),
        ];
    }

    public function testASecondEntitySealIsRefusedNamingBothEntities()
    {
        $message = $this->guard()->conflict(20, [$this->applied(10)]);

        $this->assertNotNull($message);
        $this->assertStringContainsString('Governo B', $message);
        $this->assertStringContainsString('Governo A', $message);
    }

    public function testTheConflictIsFoundAmongSeveralAppliedSeals()
    {
        $message = $this->guard()->conflict(20, [$this->applied(99), $this->applied(98), $this->applied(10)]);

        $this->assertStringContainsString('Governo A', $message);
    }

    public function testASealThatIsNotAnEntityPasses()
    {
        $this->assertNull($this->guard()->conflict(99, [$this->applied(10)]));
    }

    public function testAnEntitySealPassesWhenTheOtherAppliedSealsAreNotEntities()
    {
        $this->assertNull($this->guard()->conflict(20, [$this->applied(97), $this->applied(98)]));
    }

    public function testAnEntitySealPassesOnAnOpportunityWithoutSeals()
    {
        $this->assertNull($this->guard()->conflict(20, []));
    }

    public function testApplyingTheSameSealAgainIsIdempotent()
    {
        $this->assertNull($this->guard()->conflict(10, [$this->applied(10)]));
        $this->assertNull($this->guard()->conflict('10', [$this->applied(10), $this->applied('10')]));
    }

    public function testASealThatAlreadyBelongsToTheSameEntityDoesNotConflict()
    {
        // um ente tem um selo só (UNIQUE), mas a regra não pode depender disso para ser idempotente
        $this->entities[11] = $this->entities[10];

        $this->assertNull($this->guard()->conflict(11, [$this->applied(10)]));
    }

    public function testAPendingRequestCountsAsApplied()
    {
        $message = $this->guard()->conflict(20, [$this->applied(10, SealRelation::STATUS_PENDING)]);

        $this->assertStringContainsString('Governo A', $message);
    }

    public function testARelationOfARelatedSealStillCounts()
    {
        $this->assertNotNull($this->guard()->conflict(20, [$this->applied(10, SealRelation::STATUS_ENABLED, Seal::STATUS_RELATED)]));
    }

    public function testRelationsOfAnArchivedOrTrashedSealDoNotCount()
    {
        // não aparecem na tela da oportunidade: ninguém conseguiria removê-las para destravar
        foreach ([Seal::STATUS_DRAFT, Seal::STATUS_TRASH, Seal::STATUS_ARCHIVED] as $sealStatus) {
            $this->assertNull(
                $this->guard()->conflict(20, [$this->applied(10, SealRelation::STATUS_ENABLED, $sealStatus)]),
                "status do selo $sealStatus"
            );
        }
    }

    public function testRelationsThatAreNeitherEnabledNorPendingDoNotCount()
    {
        foreach ([SealRelation::STATUS_DRAFT, SealRelation::STATUS_TRASH, SealRelation::STATUS_DISABLED] as $status) {
            $this->assertNull($this->guard()->conflict(20, [$this->applied(10, $status)]), "status da relação $status");
        }
    }

    public function testAnEntitySealOnlyConflictsWithTheCountedOnesAmongSeveral()
    {
        $this->assertNull($this->guard()->conflict(20, [
            $this->applied(10, SealRelation::STATUS_ENABLED, Seal::STATUS_TRASH),
            $this->applied(98),
        ]));
    }

    private function applied($sealId, $status = SealRelation::STATUS_ENABLED, $sealStatus = Seal::STATUS_ENABLED)
    {
        return ['seal_id' => $sealId, 'status' => $status, 'seal_status' => $sealStatus];
    }

    private function guard()
    {
        $repository = $this->createMock(FederativeEntityRepository::class);
        $repository->method('findBySealId')->willReturnCallback(function ($sealId) {
            return isset($this->entities[(int) $sealId]) ? $this->entities[(int) $sealId] : null;
        });

        return new OpportunitySealGuard($repository);
    }

    // o construtor do core precisa do App
    private function ente($id, $name, $sealId)
    {
        $ente = (new \ReflectionClass(FederativeEntity::class))->newInstanceWithoutConstructor();
        $ref = new \ReflectionProperty(FederativeEntity::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($ente, $id);
        $ente->setName($name);
        $ente->setSealId($sealId);

        return $ente;
    }
}

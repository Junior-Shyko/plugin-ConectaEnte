<?php

namespace Tests\ConectaEnte;

use ConectaEnte\Dto\ParInformation;
use ConectaEnte\Entities\FederativeEntity;
use ConectaEnte\Repositories\FederativeEntityRepository;
use ConectaEnte\Services\EligibilityResult;
use ConectaEnte\Services\SendEligibility;
use MapasCulturais\Entities\Opportunity;
use PHPUnit\Framework\TestCase;
use Tests\ConectaEnte\Doubles\FakeOpportunity;

class SendEligibilityTest extends TestCase
{
    private $repository;
    private $entity;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(FederativeEntityRepository::class);
        $this->entity = $this->createMock(FederativeEntity::class);
        $this->entity->method('getId')->willReturn(5);
        $this->repository->method('findBySealId')->willReturnMap([[10, $this->entity], [11, null]]);
    }

    public function testPublishedSealedCompleteOpportunityIsEligible()
    {
        $result = $this->eligibility()->check($this->sealed(10));

        $this->assertTrue($result->isEligible());
        $this->assertSame($this->entity, $result->getEntity());
    }

    public function testPhaseIsNeverEligibleEvenIfComplete()
    {
        $root = $this->sealed(10);
        $phase = new FakeOpportunity(Opportunity::STATUS_ENABLED, [$this->relation(10)], $root);

        $this->assertReason(EligibilityResult::IS_PHASE, $this->eligibility()->check($phase));
    }

    /**
     * @dataProvider notPublishedStatuses
     */
    public function testDraftIsNotEligibleEvenIfComplete($status)
    {
        $this->assertReason(EligibilityResult::NOT_PUBLISHED, $this->eligibility()->check(new FakeOpportunity($status, [$this->relation(10)])));
    }

    public function notPublishedStatuses()
    {
        return [[Opportunity::STATUS_DRAFT]];
    }

    /**
     * @dataProvider archivedStatuses
     */
    public function testArchivedAndTrashAreNotEligible($status)
    {
        $this->assertReason(EligibilityResult::ARCHIVED_OR_TRASH, $this->eligibility()->check(new FakeOpportunity($status, [$this->relation(10)])));
    }

    public function archivedStatuses()
    {
        return [[Opportunity::STATUS_ARCHIVED], [Opportunity::STATUS_TRASH]];
    }

    public function testOpportunityWithoutSealRelationsHasNoSeal()
    {
        $this->assertReason(EligibilityResult::NO_SEAL, $this->eligibility()->check(new FakeOpportunity(Opportunity::STATUS_ENABLED, [])));
    }

    public function testSealOfAnotherKindIsNotAnEntitySeal()
    {
        $this->assertReason(EligibilityResult::SEAL_WITHOUT_ENTITY, $this->eligibility()->check(new FakeOpportunity(Opportunity::STATUS_ENABLED, [$this->relation(11)])));
    }

    public function testEntitySealFoundAmongOtherSealsIsEligible()
    {
        $opportunity = new FakeOpportunity(Opportunity::STATUS_ENABLED, [$this->relation(11), $this->relation(10)]);

        $result = $this->eligibility()->check($opportunity);

        $this->assertTrue($result->isEligible());
        $this->assertSame($this->entity, $result->getEntity());
    }

    public function testMissingFieldsAreNamedAndBlockTheSend()
    {
        $result = $this->eligibility(['valor_total_edital', 'pdf_edital'])->check($this->sealed(10));

        $this->assertReason(EligibilityResult::MISSING_FIELDS, $result);
        $this->assertSame(['valor_total_edital', 'pdf_edital'], $result->getMissingFields());
        $this->assertNull($result->getEntity());
    }

    public function testMissingFieldsCheckReceivesTheEntityOfTheSeal()
    {
        $received = null;
        $eligibility = new SendEligibility($this->repository, function ($opportunity, $entity) use (&$received) {
            $received = $entity;

            return [];
        });

        $eligibility->check($this->sealed(10));

        $this->assertSame($this->entity, $received);
    }

    public function testTheCachedParTreeOfTheEntityReachesTheMissingFieldsCheck()
    {
        $tree = ParInformation::fromArray(['cnpj' => '00000000000191', 'exercicios' => []]);
        $received = 'nao chamado';
        $eligibility = new SendEligibility($this->repository, function ($opportunity, $entity, $treeArg) use (&$received) {
            $received = $treeArg;

            return [];
        }, function ($entity) use ($tree) {
            return $this->entity === $entity ? $tree : null;
        });

        $eligibility->check($this->sealed(10));

        $this->assertSame($tree, $received);
    }

    private function eligibility(array $missing = [])
    {
        return new SendEligibility($this->repository, function () use ($missing) {
            return $missing;
        });
    }

    private function sealed($sealId)
    {
        return new FakeOpportunity(Opportunity::STATUS_ENABLED, [$this->relation($sealId)]);
    }

    private function relation($sealId)
    {
        return (object) ['seal' => (object) ['id' => $sealId]];
    }

    private function assertReason($reason, EligibilityResult $result)
    {
        $this->assertFalse($result->isEligible());
        $this->assertSame($reason, $result->getReason());
    }
}

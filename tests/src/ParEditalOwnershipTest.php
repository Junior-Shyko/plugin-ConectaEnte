<?php

namespace Tests\ConectaEnte;

use ConectaEnte\Services\ParEditalOwnership;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

class ParEditalOwnershipTest extends TestCase
{
    public function testFreeIdHasNoOtherOwner()
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchColumn')->willReturn(false);

        $this->assertNull((new ParEditalOwnership($connection))->otherOwner('9', 7));
    }

    public function testReturnsTheOpportunityThatAlreadyHoldsTheId()
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('fetchColumn')
            ->with($this->stringContains('object_id <> :opportunity'), ['key' => 'conectaente_parEditalId', 'value' => '9', 'opportunity' => 7])
            ->willReturn('12');

        $this->assertSame(12, (new ParEditalOwnership($connection))->otherOwner('9', 7));
    }
}

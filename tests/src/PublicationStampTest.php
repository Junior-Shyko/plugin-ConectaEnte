<?php

namespace Tests\ConectaEnte;

use ConectaEnte\Services\PublicationStamp;
use PHPUnit\Framework\TestCase;

class PublicationStampTest extends TestCase
{
    public function testAStoredDateIsReadAsThatDay()
    {
        $this->assertSame('2026-11-03', PublicationStamp::parse('2026-11-03')->format('Y-m-d'));
    }

    public function testTheTimePrefixOfAnOlderValueIsIgnored()
    {
        $this->assertSame('2026-10-01', PublicationStamp::parse('2026-10-01 10:00:00')->format('Y-m-d'));
    }

    public function testAnImpossibleDateIsRefusedInsteadOfRolledOver()
    {
        $this->assertNull(PublicationStamp::parse('2026-02-30'));
        $this->assertNull(PublicationStamp::parse('2026-13-01'));
    }

    public function testEmptyOrNonDateValuesHaveNoDate()
    {
        $this->assertNull(PublicationStamp::parse(''));
        $this->assertNull(PublicationStamp::parse(null));
        $this->assertNull(PublicationStamp::parse('03/11/2026'));
        $this->assertNull(PublicationStamp::parse(['2026-11-03']));
    }
}

<?php

namespace Tests\ConectaEnte;

use ConectaEnte\Services\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function testPtBrAndPlainFormatsBecomeTheContractDecimal()
    {
        $this->assertSame('1500000.50', Money::toDecimal('1.500.000,50'));
        $this->assertSame('1500000.50', Money::toDecimal('1500000,50'));
        $this->assertSame('1500000.50', Money::toDecimal('1500000.50'));
        $this->assertSame('1.50', Money::toDecimal('1,5'));
        $this->assertSame('150000.00', Money::toDecimal(' 150000 '));
    }

    public function testADotFollowedByThreeDigitsIsAThousandsSeparator()
    {
        $this->assertSame('1500.00', Money::toDecimal('1.500'));
        $this->assertSame('1500000.00', Money::toDecimal('1.500.000'));
        $this->assertSame('1.50', Money::toDecimal('1.5'));
    }

    public function testNumbersGoOutWithTwoDecimals()
    {
        $this->assertSame('10.00', Money::toDecimal(10));
        $this->assertSame('10.50', Money::toDecimal(10.5));
    }

    public function testAGroupingThatIsNotValidIsRefusedInsteadOfSilentlyChangingTheValue()
    {
        // antes: "1,000.50" virava 1.00 e "12.34,56" virava 1234.56, sem aviso
        $this->assertNull(Money::toDecimal('1,000.50'));
        $this->assertNull(Money::toDecimal('12.34,56'));
        $this->assertNull(Money::toDecimal('1.50,00'));
        $this->assertSame('1500.00', Money::toDecimal('1.500,00'));
        $this->assertSame('1500000.50', Money::toDecimal('1.500.000,50'));
    }

    public function testLargeAmountsKeepEveryDigit()
    {
        // antes passava por float e virava 9007199254740992.00
        $this->assertSame('9007199254740993.00', Money::toDecimal('9007199254740993'));
        $this->assertSame('9007199254740993.50', Money::toDecimal('9007199254740993,5'));
        $this->assertSame('9223372036854775807.00', Money::toDecimal(PHP_INT_MAX));
    }

    public function testMoreThanTwoDecimalsAreRoundedHalfUp()
    {
        // com vírgula, três casas são decimais; com ponto, três casas são milhar, então o caso usa quatro
        $this->assertSame('1.01', Money::toDecimal('1,005'));
        $this->assertSame('1.00', Money::toDecimal('1,004'));
        $this->assertSame('1.01', Money::toDecimal('1.0050'));
        $this->assertSame('1.00', Money::toDecimal('1.0049'));
        // a regra só olha a terceira casa: 0,00499… arredonda para baixo
        $this->assertSame('1.00', Money::toDecimal('1,0049999'));
    }

    public function testRoundingCarriesIntoTheIntegerPart()
    {
        $this->assertSame('1000.00', Money::toDecimal('999,999'));
        $this->assertSame('10.00', Money::toDecimal('9,999'));
        $this->assertSame('0.01', Money::toDecimal('0,005'));
        $this->assertSame('0.00', Money::toDecimal('0,004'));
    }

    public function testAZeroBeforeTheDotIsNeverAThousandsSeparator()
    {
        $this->assertSame('0.50', Money::toDecimal('0.500'));
        $this->assertSame('0.01', Money::toDecimal('0.005'));
    }

    public function testNegativeIntegersAndFloatsAreRefusedLikeNegativeStrings()
    {
        $this->assertNull(Money::toDecimal(-5));
        $this->assertNull(Money::toDecimal(-5.5));
        $this->assertSame('0.00', Money::toDecimal(0));
    }

    public function testWhatIsNotAPositiveAmountOrANumberHasNoDecimal()
    {
        $this->assertNull(Money::toDecimal(''));
        $this->assertNull(Money::toDecimal(null));
        $this->assertNull(Money::toDecimal('abc'));
        $this->assertNull(Money::toDecimal('-5'));
        $this->assertNull(Money::toDecimal('1.50.0'));
        $this->assertSame('0.00', Money::toDecimal('0,00'));
    }
}

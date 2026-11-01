<?php

namespace Tests\ConectaEnte;

use ConectaEnte\Metadata\JsonBlock;
use ConectaEnte\Services\FundingSourceName;
use PHPUnit\Framework\TestCase;

class JsonBlockTest extends TestCase
{
    public function testValidJsonTextIsStoredAsIsWithoutBeingEncodedAgain()
    {
        $this->assertSame('{"opcoes":["a"]}', JsonBlock::serialize('{"opcoes":["a"]}'));
        $this->assertSame('{"opcoes":["a"]}', JsonBlock::serialize(' { "opcoes" : ["a"] } '));
    }

    public function testListsAndObjectsAreEncoded()
    {
        $this->assertSame('{"a":1}', JsonBlock::serialize(['a' => 1]));
        $this->assertSame('{"a":1}', JsonBlock::serialize((object) ['a' => 1]));
        $this->assertSame('[{"label":"Ampla concorrência"}]', JsonBlock::serialize([['label' => 'Ampla concorrência']]));
    }

    public function testEmptyInvalidOrScalarBecomesNoBlock()
    {
        foreach ([null, '', '  ', 'not json', '"text"', '12', 12, true, '{'] as $value) {
            $this->assertNull(JsonBlock::serialize($value), var_export($value, true));
        }
    }

    public function testSanitizerReceivesTheDecodedBlock()
    {
        $json = JsonBlock::serialize('{"x":" y "}', function (array $block) {
            $block['x'] = trim($block['x']);

            return $block;
        });

        $this->assertSame('{"x":"y"}', $json);
    }

    public function testUnserializeReturnsAnAssociativeArrayOrNull()
    {
        $this->assertSame(['a' => [1, 2]], JsonBlock::unserialize('{"a":[1,2]}'));
        $this->assertNull(JsonBlock::unserialize(null));
        $this->assertNull(JsonBlock::unserialize(''));
        $this->assertNull(JsonBlock::unserialize('not json'));
        $this->assertNull(JsonBlock::unserialize('12'));
    }

    public function testFundingSourceNameIsTrimmedStrippedAndCut()
    {
        $service = new FundingSourceName();

        $this->assertSame('Fundo', $service->sanitize("  <i>Fundo</i>\n"));
        $this->assertSame(255, mb_strlen($service->sanitize(str_repeat('á', 300))));
        $this->assertSame('', $service->sanitize(['x']));
    }

    public function testFundingSourceBlockSanitizesOnlyTheFreeSourceNames()
    {
        $service = new FundingSourceName();
        $block = ['recursosProprios' => 5, 'outrasFontes' => [['nomeFonte' => ' <b>A</b> ', 'valor' => 1], ['valor' => 2]]];

        $clean = $service->sanitizeBlock($block);

        $this->assertSame('A', $clean['outrasFontes'][0]['nomeFonte']);
        $this->assertSame(['valor' => 2], $clean['outrasFontes'][1]);
        $this->assertSame(5, $clean['recursosProprios']);
    }

    public function testFundingSourceBlockWithoutFreeSourcesIsUntouched()
    {
        $service = new FundingSourceName();

        $this->assertSame(['outrasFontes' => null], $service->sanitizeBlock(['outrasFontes' => null]));
        $this->assertSame('x', $service->sanitizeBlock('x'));
    }
}

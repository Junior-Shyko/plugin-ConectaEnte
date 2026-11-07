<?php

namespace Tests\ConectaEnte;

use ConectaEnte\Dto\ParInformation;
use PHPUnit\Framework\TestCase;

class ParInformationDtoTest extends TestCase
{
    private function entity(array $override = [])
    {
        return array_merge([
            'cnpj' => '00.000.000/0001-91',
            'nome' => 'Ente de teste',
            'exercicios' => [[
                'id' => 9001,
                'ano' => 2025,
                'metas' => [[
                    'id' => 9101,
                    'nome' => 'Fomento',
                    'valor' => '1500000.00',
                    'acoes' => [[
                        'id' => 9201,
                        'nome' => null,
                        'valor' => null,
                        'atividades' => [['id' => 9301, 'nome' => 'Editais', 'valor' => '1200000.00']],
                    ]],
                ]],
            ]],
        ], $override);
    }

    public function testReadsTheWholeHierarchyWithIdsAsStrings()
    {
        $tree = ParInformation::fromArray($this->entity());

        $this->assertSame('00000000000191', $tree->cnpj);
        $exercise = $tree->exercises[0];
        $this->assertSame('9001', $exercise->id);
        $this->assertSame('2025', $exercise->name);
        $this->assertSame('9101', $tree->exercises[0]->goals[0]->id);
        $this->assertSame('9301', $tree->exercises[0]->goals[0]->actions[0]->activities[0]->id);
        $this->assertSame('1200000.00', $tree->exercises[0]->goals[0]->actions[0]->activities[0]->value);
    }

    public function testANodeWithoutNameIsShownByItsId()
    {
        $tree = ParInformation::fromArray($this->entity());
        $action = $tree->exercises[0]->goals[0]->actions[0];

        $this->assertNull($action->name);
        $this->assertSame('Id 9201', $action->label());
    }

    public function testANodeWithoutIdIsDroppedInsteadOfBreakingTheTree()
    {
        $tree = ParInformation::fromArray($this->entity([
            'exercicios' => [
                ['ano' => 2024, 'metas' => []],
                ['id' => 9002, 'ano' => 2026, 'metas' => [['nome' => 'sem id'], ['id' => 9102, 'nome' => 'Meta']]],
            ],
        ]));

        $this->assertCount(1, $tree->exercises);
        $this->assertSame('9002', $tree->exercises[0]->id);
        $this->assertCount(1, $tree->exercises[0]->goals);
        $this->assertSame('9102', $tree->exercises[0]->goals[0]->id);
    }

    public function testAnItemWithoutCnpjIsNotATree()
    {
        $this->assertNull(ParInformation::fromArray(['nome' => 'sem cnpj', 'exercicios' => []]));
    }
}

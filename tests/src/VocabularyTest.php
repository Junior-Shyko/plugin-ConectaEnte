<?php

namespace Tests\ConectaEnte;

use ConectaEnte\Vocabulary\AbstractVocabulary;
use ConectaEnte\Vocabulary\AffirmativeAction;
use ConectaEnte\Vocabulary\AffirmativeActionGroup;
use ConectaEnte\Vocabulary\FundingSource;
use ConectaEnte\Vocabulary\LegalQuota;
use ConectaEnte\Vocabulary\ExecutionType;
use ConectaEnte\Vocabulary\ProponentType;
use ConectaEnte\Vocabulary\RegistrationChannel;
use ConectaEnte\Vocabulary\Segment;
use ConectaEnte\Vocabulary\CulturalStage;
use ConectaEnte\Vocabulary\PriorityTerritory;
use ConectaEnte\Vocabulary\ThematicAgenda;
use PHPUnit\Framework\TestCase;

class VocabularyTest extends TestCase
{
    // a Conecta Ente exige estes valores sem publicá-los; vêm dos contratos de Gestão e Conecta MinC
    const CONTRACT_PROPONENT_TYPES = [
        'pessoa_fisica',
        'mei_microempreendedor_individual',
        'pessoa_juridica_com_fins_lucrativos_empresas',
        'pessoa_juridica_sem_fins_lucrativos',
        'coletivos_e_grupos_informais_sem_cnpj',
        'pontos_de_cultura_com_constituicao_juridica',
        'pontos_de_cultura_sem_constituicao_juridica',
        'organizacoes_da_sociedade_civil_sem_fins_lucrativos',
    ];

    const CONTRACT_REGISTRATION_CHANNELS = ['email', 'presencial', 'correio', 'oral', 'sistema', 'outros'];

    function vocabularies()
    {
        return [
            'ProponentType' => [ProponentType::class, 8],
            'RegistrationChannel' => [RegistrationChannel::class, 6],
            'ExecutionType' => [ExecutionType::class, 12],
            'Segment' => [Segment::class, 28],
            'CulturalStage' => [CulturalStage::class, 11],
            'ThematicAgenda' => [ThematicAgenda::class, 29],
            'PriorityTerritory' => [PriorityTerritory::class, 13],
            'AffirmativeAction' => [AffirmativeAction::class, 6],
            'AffirmativeActionGroup' => [AffirmativeActionGroup::class, 9],
            'LegalQuota' => [LegalQuota::class, 4],
            'FundingSource' => [FundingSource::class, 5],
        ];
    }

    /**
     * @dataProvider vocabularies
     */
    function testCasesHaveTheExpectedCountAndNoDuplicates(string $class, int $count)
    {
        $cases = $class::cases();

        $this->assertCount($count, $cases);
        $this->assertSame($cases, array_values(array_unique($cases)));
    }

    /**
     * @dataProvider vocabularies
     */
    function testTryFromAcceptsEveryCaseAndRejectsTheRest(string $class)
    {
        foreach ($class::cases() as $value) {
            $this->assertSame($value, $class::tryFrom($value));
        }

        foreach (['', 'valor_inexistente', 'pessoa_juridica', 0, 1, null, false, true, []] as $invalid) {
            $this->assertNull($class::tryFrom($invalid), 'aceitou ' . var_export($invalid, true));
        }
    }

    /**
     * @dataProvider vocabularies
     */
    function testLabelIsReadableForCasesAndNullForUnknownValues(string $class)
    {
        foreach ($class::cases() as $value) {
            $this->assertIsString($class::label($value));
            $this->assertNotSame('', $class::label($value));
        }

        $this->assertNull($class::label('valor_inexistente'));
        $this->assertNull($class::label(0));
    }

    /**
     * @dataProvider vocabularies
     */
    function testOptionsMapsEveryCaseToItsLabelInOrder(string $class)
    {
        $options = $class::options();

        $this->assertSame($class::cases(), array_map('strval', array_keys($options)));

        foreach ($options as $value => $label) {
            $this->assertSame($class::label((string) $value), $label);
        }
    }

    /**
     * @dataProvider vocabularies
     */
    function testOnlyValueConstantsAreDeclaredByTheSubclass(string $class)
    {
        $this->assertInstanceOf(AbstractVocabulary::class, new $class());
        $this->assertSame(
            count($class::cases()),
            count((new \ReflectionClass($class))->getConstants())
        );
    }

    function testProponentTypesAreExactlyTheContractOnes()
    {
        $this->assertSame(self::CONTRACT_PROPONENT_TYPES, ProponentType::cases());
        $this->assertNull(ProponentType::tryFrom('pessoa_juridica'));
    }

    function testProponentTypeLabels()
    {
        $this->assertSame('Pessoa Física', ProponentType::label('pessoa_fisica'));
        $this->assertSame('Pontos de Cultura sem constituição jurídica', ProponentType::label('pontos_de_cultura_sem_constituicao_juridica'));
    }

    function testRegistrationChannelsAreExactlyTheContractOnes()
    {
        $this->assertSame(self::CONTRACT_REGISTRATION_CHANNELS, RegistrationChannel::cases());
        $this->assertNull(RegistrationChannel::tryFrom('correspondencia'));
        $this->assertNull(RegistrationChannel::tryFrom('oralidade'));
        $this->assertSame('Sistema digital', RegistrationChannel::label('sistema'));
    }

    function testEditalTypeListIsThePnabOne()
    {
        $this->assertSame([
            'Execução cultural',
            'Subsídio a espaços culturais',
            'Bolsa cultural',
            'Premiação cultural',
            'TCC Pontos de Cultura',
            'TCC Pontões de Cultura',
            'Bolsa Cultura Viva',
            'Premiação Cultura Viva',
            'Programa Nacional de Ações Continuadas',
            'Programa Nacional de Infraestrutura Cultural',
            'Programa Nacional de Formação para Gestores',
            'Outros',
        ], ExecutionType::cases());
    }

    function testValueIsTheTextWhereTheSniicListsKeyByLabel()
    {
        $this->assertSame('Teatro', Segment::label(Segment::THEATER));
        $this->assertSame('Outros (especificar)', Segment::label(Segment::OTHER));
        $this->assertSame('Criação', CulturalStage::label('Criação'));
        $this->assertSame('Território rural', PriorityTerritory::label(PriorityTerritory::RURAL_TERRITORY));
        $this->assertSame('Cultura Digital', ThematicAgenda::label(ThematicAgenda::DIGITAL_CULTURE));
    }

    function testAffirmativeActionKeysAndLabels()
    {
        $this->assertSame(
            ['nao_previstas', 'bonus_agentes', 'bonus_tematicas', 'categoria_especifica', 'edital_especifico', 'outra_legislacao'],
            AffirmativeAction::cases()
        );
        $this->assertSame('Categoria específica', AffirmativeAction::label('categoria_especifica'));
    }

    function testBlockVocabulariesCarryTheV7BranchValues()
    {
        $this->assertSame(
            ['Pessoas negras (pretas e pardas)', 'Pessoas indígenas', 'Pessoas com deficiência', 'Ampla concorrência'],
            LegalQuota::cases()
        );
        $this->assertSame(['Pessoas negras (pretas e pardas)', 'Pessoas indígenas', 'Pessoas com deficiência'], LegalQuota::legal());
        $this->assertSame(
            ['recursosProprios', 'conveniosParcerias', 'emendasParlamentares', 'remanescentesCiclo1', 'outrasFontes'],
            FundingSource::cases()
        );
        $this->assertSame(
            ['pessoas_negras', 'pessoas_indigenas', 'pessoas_deficiencia', 'mulheres', 'povos_tradicionais', 'lgbtqiapn', 'pessoas_idosas', 'situacao_rua', 'outros_vulnerabilizados'],
            AffirmativeActionGroup::cases()
        );
        $this->assertSame('LGBTQIAPN+', AffirmativeActionGroup::label('lgbtqiapn'));
    }

    function testAffirmativeActionKnowsWhichOnesNeedGroupsAndWhichIsExclusive()
    {
        foreach (['bonus_agentes', 'bonus_tematicas', 'categoria_especifica', 'edital_especifico'] as $value) {
            $this->assertTrue(AffirmativeAction::hasGroups($value), $value);
        }
        $this->assertFalse(AffirmativeAction::hasGroups('nao_previstas'));
        $this->assertFalse(AffirmativeAction::hasGroups('outra_legislacao'));
        $this->assertTrue(AffirmativeAction::isExclusive('nao_previstas'));
        $this->assertFalse(AffirmativeAction::isExclusive('bonus_agentes'));
        $this->assertSame(140, AffirmativeAction::otherLegislationMaxLength());
    }
}

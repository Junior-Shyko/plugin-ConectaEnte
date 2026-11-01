<?php

namespace Tests\ConectaEnte;

use ConectaEnte\Metadata\CultBrMetadata as Meta;
use ConectaEnte\Vocabulary\CulturalStage;
use ConectaEnte\Vocabulary\ExecutionType;
use ConectaEnte\Vocabulary\Segment;
use ConectaEnte\Vocabulary\TargetingField;
use ConectaEnte\Vocabulary\TargetingOption;
use ConectaEnte\Vocabulary\ThematicAgenda;
use PHPUnit\Framework\TestCase;

class CultBrMetadataTest extends TestCase
{
    public function testRegistersTheTwelveKeysWithThePluginPrefixOfTheDevelopBranch()
    {
        $this->assertSame([
            'conectaente_executionType',
            'conectaente_segments',
            'conectaente_segmentsOther',
            'conectaente_culturalStages',
            'conectaente_culturalStagesOther',
            'conectaente_thematicAgendas',
            'conectaente_thematicAgendasOther',
            'conectaente_priorityTerritories',
            'conectaente_fundingSources',
            'conectaente_quotaReservation',
            'conectaente_registrationChannels',
            'conectaente_affirmativeActions',
        ], Meta::keys());

        foreach (Meta::keys() as $key) {
            $this->assertStringStartsWith(Meta::PREFIX, $key);
        }
    }

    public function testRegisterHandsEveryDefinitionToTheRegistrar()
    {
        $registrar = new class {
            public $registered = [];

            public function registerOpportunityMetadata($key, $config)
            {
                $this->registered[$key] = $config;
            }
        };

        Meta::register($registrar);

        $this->assertSame(Meta::keys(), array_keys($registrar->registered));
        foreach (Meta::definitions() as $key => $definition) {
            $this->assertSame($definition['type'], $registrar->registered[$key]['type'], $key);
            $this->assertSame($definition['label'], $registrar->registered[$key]['label'], $key);
        }
    }

    public function testExecutionTypeIsASelectAndTheTargetingFieldsMultiselects()
    {
        $definitions = Meta::definitions();

        $this->assertSame('select', $definitions[Meta::EXECUTION_TYPE]['type']);
        foreach (TargetingField::cases() as $field) {
            $this->assertSame('multiselect', $definitions[TargetingField::metadataKey($field)]['type'], $field);
        }
        foreach ([Meta::SEGMENTS_OTHER, Meta::CULTURAL_STAGES_OTHER, Meta::THEMATIC_AGENDAS_OTHER] as $key) {
            $this->assertSame('string', $definitions[$key]['type'], $key);
        }
    }

    public function testExecutionTypeOptionsAreTheVocabularyValues()
    {
        $this->assertSame(ExecutionType::cases(), array_keys(Meta::definitions()[Meta::EXECUTION_TYPE]['options']));
    }

    public function testEveryTargetingFieldStartsWithTheNotTargetedOptionBeforeTheVocabulary()
    {
        foreach (TargetingField::cases() as $field) {
            $options = Meta::definitions()[TargetingField::metadataKey($field)]['options'];
            $vocabulary = TargetingField::vocabulary($field);

            $this->assertSame(TargetingOption::NOT_TARGETED, array_keys($options)[0], $field);
            $this->assertSame(TargetingField::notTargetedText($field), $options[TargetingOption::NOT_TARGETED], $field);
            $this->assertSame($vocabulary::cases(), array_slice(array_keys($options), $field === TargetingField::SEGMENT ? 2 : 1), $field);
        }
    }

    public function testOnlyTheSegmentHasTheAllOptionsChoice()
    {
        foreach (TargetingField::cases() as $field) {
            $options = Meta::definitions()[TargetingField::metadataKey($field)]['options'];

            $this->assertSame($field === TargetingField::SEGMENT, isset($options[TargetingOption::ALL_OPTIONS]), $field);
        }
        $this->assertSame('Todas as opções', Meta::definitions()[Meta::SEGMENTS]['options'][TargetingOption::ALL_OPTIONS]);
    }

    public function testTheFourBlocksAreJsonMetadataWithTheirOwnSerializers()
    {
        foreach ([Meta::FUNDING_SOURCES, Meta::QUOTA_RESERVATION, Meta::REGISTRATION_CHANNELS, Meta::AFFIRMATIVE_ACTIONS] as $key) {
            $definition = Meta::definitions()[$key];

            $this->assertSame('json', $definition['type'], $key);
            $this->assertIsCallable($definition['serialize'], $key);
            $this->assertIsCallable($definition['unserialize'], $key);
            // o texto JSON que o front envia não pode ser codificado de novo
            $this->assertSame('{"a":1}', $definition['serialize']('{"a":1}'), $key);
            $this->assertSame(['a' => 1], $definition['unserialize']('{"a":1}'), $key);
        }
    }

    public function testFundingSourceNamesAreSanitizedOnSerialize()
    {
        $serialize = Meta::definitions()[Meta::FUNDING_SOURCES]['serialize'];

        $json = $serialize('{"outrasFontes":[{"nomeFonte":"  <b>Fundo</b> X ","valor":10}]}');

        $this->assertSame('Fundo X', json_decode($json, true)['outrasFontes'][0]['nomeFonte']);
    }

    public function testNoOptionCarriesTheMultiselectSeparator()
    {
        foreach (Meta::definitions() as $key => $definition) {
            foreach (isset($definition['options']) ? array_keys($definition['options']) : [] as $value) {
                $this->assertStringNotContainsString(Meta::SEPARATOR, $value, $key);
            }
        }
    }

    public function testSyntheticValuesAreTheOnesOfTheV7Branch()
    {
        $this->assertSame('__edital_nao_se_direciona__', TargetingOption::NOT_TARGETED);
        $this->assertSame('__todas_opcoes__', TargetingOption::ALL_OPTIONS);
        $this->assertTrue(TargetingOption::isSynthetic('__todas_opcoes__'));
        $this->assertFalse(TargetingOption::isSynthetic('Música'));
    }

    public function testNotTargetedTextNamesTheField()
    {
        $this->assertSame('Edital não se direciona a segmentos específicos', TargetingOption::text(TargetingOption::NOT_TARGETED, TargetingField::SEGMENT));
        $this->assertSame('Edital não se direciona a etapa específica', TargetingOption::text(TargetingOption::NOT_TARGETED, TargetingField::CULTURAL_STAGE));
        $this->assertSame('Edital não se direciona a pautas específicas', TargetingOption::text(TargetingOption::NOT_TARGETED, TargetingField::THEMATIC_AGENDA));
        $this->assertSame('Edital não se direciona a territórios específicos', TargetingOption::text(TargetingOption::NOT_TARGETED, TargetingField::PRIORITY_TERRITORY));
    }

    public function testUnknownFieldOrOptionFailsLoudly()
    {
        $this->expectException(\InvalidArgumentException::class);

        TargetingOption::text('__outra__', TargetingField::SEGMENT);
    }

    public function testUnknownFieldFailsLoudly()
    {
        $this->expectException(\InvalidArgumentException::class);

        TargetingField::notTargetedText('inexistente');
    }

    public function testFieldIsFoundByItsMetadataKey()
    {
        $this->assertSame(TargetingField::THEMATIC_AGENDA, TargetingField::fromMetadataKey(Meta::THEMATIC_AGENDAS));
        $this->assertNull(TargetingField::fromMetadataKey(Meta::EXECUTION_TYPE));
    }

    public function testValuesAcceptTheCoreTextOrAList()
    {
        $this->assertSame(['A', 'B'], Meta::values('A;B'));
        $this->assertSame(['A', 'B'], Meta::values(' A ; B ;'));
        $this->assertSame(['A', 'B'], Meta::values(['A', ' B', '']));
        $this->assertSame([], Meta::values(''));
        $this->assertSame([], Meta::values(null));
    }

    public function testManyValuesBecomeOneTextWithLabels()
    {
        $this->assertSame('Música, Teatro', Meta::toText('Música;Teatro'));
        $this->assertSame('Música, Teatro', Meta::toText(['Música', 'Teatro']));
        $this->assertSame('', Meta::toText(null));
    }

    public function testSyntheticValuesBecomeTheirTextInThePayload()
    {
        $this->assertSame(
            'Edital não se direciona a segmentos específicos',
            Meta::toText(TargetingOption::NOT_TARGETED, Meta::SEGMENTS)
        );
        $this->assertSame('Todas as opções', Meta::toText(TargetingOption::ALL_OPTIONS, Meta::SEGMENTS));
        $this->assertSame(
            'Edital não se direciona a territórios específicos',
            Meta::toText(TargetingOption::NOT_TARGETED, Meta::PRIORITY_TERRITORIES)
        );
    }

    public function testWithoutAFieldTheSyntheticValueIsLeftAlone()
    {
        $this->assertSame(TargetingOption::NOT_TARGETED, Meta::toText(TargetingOption::NOT_TARGETED));
    }

    public function testSpecificationIsMissingWhenTheOtherOptionIsChosenWithoutDetail()
    {
        $this->assertSame([Meta::SEGMENTS_OTHER], Meta::missingSpecifications([Meta::SEGMENTS => 'Música;' . Segment::OTHER]));
        $this->assertSame([Meta::CULTURAL_STAGES_OTHER], Meta::missingSpecifications([Meta::CULTURAL_STAGES => CulturalStage::OTHER, Meta::CULTURAL_STAGES_OTHER => '  ']));
        $this->assertSame([Meta::THEMATIC_AGENDAS_OTHER], Meta::missingSpecifications([Meta::THEMATIC_AGENDAS => [ThematicAgenda::OTHER]]));
    }

    public function testSpecificationIsNotMissingWhenDetailedOrNotNeeded()
    {
        $this->assertSame([], Meta::missingSpecifications([Meta::SEGMENTS => Segment::OTHER, Meta::SEGMENTS_OTHER => 'Cordel']));
        $this->assertSame([], Meta::missingSpecifications([Meta::SEGMENTS => 'Música']));
        $this->assertSame([], Meta::missingSpecifications([]));
    }
}

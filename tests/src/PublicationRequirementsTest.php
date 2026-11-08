<?php

namespace Tests\ConectaEnte;

use ConectaEnte\Dto\ParInformation;
use ConectaEnte\Metadata\CultBrMetadata as Meta;
use ConectaEnte\Services\PublicationRequirements;
use PHPUnit\Framework\TestCase;

class OpportunityDouble
{
    private $values;
    private $hasRules;

    public function __construct(array $values, $hasRules = true)
    {
        $this->values = $values;
        $this->hasRules = $hasRules;
    }

    public function __get($name)
    {
        return array_key_exists($name, $this->values) ? $this->values[$name] : null;
    }

    public function getFile($group)
    {
        return $group === 'rules' && $this->hasRules ? new \stdClass() : null;
    }
}

class PublicationRequirementsTest extends TestCase
{
    private function complete(array $override = [])
    {
        return array_merge([
            'numberVacancies' => '10',
            'shortDescription' => 'Edital de teste',
            'longDescription' => null,
            'registrationFrom' => new \DateTime('2026-01-01'),
            'registrationTo' => new \DateTime('2026-02-01'),
            Meta::TOTAL_RESOURCE => '50000,50',
            Meta::PROPONENT_TYPES => 'pessoa_fisica;mei_microempreendedor_individual',
            Meta::PAR_EXERCISE_ID => '2026',
            Meta::PAR_GOAL_ID => '3',
            Meta::PAR_ACTION_ID => '4',
            Meta::PAR_ACTIVITY_ID => '5',
            Meta::EXECUTION_TYPE => 'Execução cultural',
            Meta::SEGMENTS => '__edital_nao_se_direciona__',
            Meta::SEGMENTS_OTHER => null,
            Meta::CULTURAL_STAGES => 'Formação',
            Meta::CULTURAL_STAGES_OTHER => null,
            Meta::THEMATIC_AGENDAS => 'Cultura e Juventude',
            Meta::THEMATIC_AGENDAS_OTHER => null,
            Meta::PRIORITY_TERRITORIES => '__edital_nao_se_direciona__',
            Meta::FUNDING_SOURCES => ['houveUtilizacao' => 'nao'],
            Meta::QUOTA_RESERVATION => [
                ['label' => 'Pessoas negras (pretas e pardas)', 'vagas' => 3, 'valorDestinado' => 0, 'naoAplicavel' => false],
                ['label' => 'Pessoas indígenas', 'vagas' => 1, 'valorDestinado' => 0, 'naoAplicavel' => false],
                ['label' => 'Pessoas com deficiência', 'vagas' => 1, 'valorDestinado' => 0, 'naoAplicavel' => false],
                ['label' => 'Ampla concorrência', 'vagas' => 5, 'valorDestinado' => 0, 'naoAplicavel' => false],
            ],
            Meta::REGISTRATION_CHANNELS => ['previstasNoEdital' => 'nao', 'formas' => []],
            Meta::AFFIRMATIVE_ACTIONS => ['opcoes' => ['nao_previstas']],
        ], $override);
    }

    private function missing(array $override = [], $hasRules = true)
    {
        return (new PublicationRequirements())->missingFields(new OpportunityDouble($this->complete($override), $hasRules));
    }

    /** Árvore do ente com dois exercícios: 2026 (meta 3 → ação 4 → atividade 5, ação 9 → atividade 6) e 2027 (meta 8). */
    private function tree()
    {
        return ParInformation::fromArray([
            'cnpj' => '00000000000191',
            'nome' => 'Ente de teste',
            'exercicios' => [
                ['id' => 2026, 'ano' => 2026, 'metas' => [
                    ['id' => 3, 'nome' => 'Meta', 'valor' => '1500000.00', 'acoes' => [
                        ['id' => 4, 'nome' => 'Ação', 'valor' => '1200000.00', 'atividades' => [['id' => 5, 'nome' => 'Edital', 'valor' => '700000.00']]],
                        ['id' => 9, 'nome' => 'Outra ação', 'valor' => '1.00', 'atividades' => [['id' => 6, 'nome' => 'Outro edital', 'valor' => '1.00']]],
                    ]],
                    ['id' => 10, 'nome' => 'Outra meta', 'valor' => '1.00', 'acoes' => [
                        ['id' => 11, 'nome' => 'Ação da outra meta', 'valor' => '1.00', 'atividades' => [['id' => 12, 'nome' => 'Edital da outra meta', 'valor' => '1.00']]],
                    ]],
                ]],
                ['id' => 2027, 'ano' => 2027, 'metas' => [
                    ['id' => 8, 'nome' => 'Meta de 2027', 'valor' => '1.00', 'acoes' => []],
                ]],
            ],
        ]);
    }

    private function chainMissing(array $override = [], ParInformation $tree = null)
    {
        return (new PublicationRequirements())->missingFields(new OpportunityDouble($this->complete($override)), $tree ?: $this->tree());
    }

    public function testCompleteOpportunityHasNothingMissing()
    {
        $this->assertSame([], $this->missing());
    }

    public function testAChainThatExistsInTheTreeIsAccepted()
    {
        $this->assertSame([], $this->chainMissing());
        $this->assertSame([], $this->chainMissing([Meta::PAR_EXERCISE_ID => '02026']));
    }

    public function testWithoutATreeTheChainIsNotChecked()
    {
        $missing = (new PublicationRequirements())->missingFields(new OpportunityDouble($this->complete([Meta::PAR_ACTIVITY_ID => '999'])), null);

        $this->assertSame([], $missing);
    }

    public function testAnExerciseOutOfTheTreeIsNamed()
    {
        $missing = $this->chainMissing([Meta::PAR_EXERCISE_ID => '9999']);

        $this->assertSame([Meta::PAR_EXERCISE_ID], array_keys($missing));
        $this->assertStringContainsString('não existe na árvore', $missing[Meta::PAR_EXERCISE_ID][0]);
    }

    public function testAGoalOfAnotherExerciseIsRefused()
    {
        $missing = $this->chainMissing([Meta::PAR_EXERCISE_ID => '2027']);

        $this->assertSame([Meta::PAR_GOAL_ID], array_keys($missing));
        $this->assertStringContainsString('não pertence ao Exercício', $missing[Meta::PAR_GOAL_ID][0]);
    }

    public function testAnActionOfAnotherGoalIsRefused()
    {
        // duas metas com ações no mesmo exercício: a ação 4 existe na árvore, mas pertence à meta 3
        $missing = $this->chainMissing([Meta::PAR_GOAL_ID => '10', Meta::PAR_ACTION_ID => '4', Meta::PAR_ACTIVITY_ID => '5']);

        $this->assertSame([Meta::PAR_ACTION_ID], array_keys($missing));
        $this->assertSame([], $this->chainMissing([Meta::PAR_GOAL_ID => '10', Meta::PAR_ACTION_ID => '11', Meta::PAR_ACTIVITY_ID => '12']));
    }

    public function testAnActivityOfAnotherActionIsRefused()
    {
        $missing = $this->chainMissing([Meta::PAR_ACTION_ID => '4', Meta::PAR_ACTIVITY_ID => '6']);

        $this->assertSame([Meta::PAR_ACTIVITY_ID], array_keys($missing));
        $this->assertStringContainsString('não pertence à Ação', $missing[Meta::PAR_ACTIVITY_ID][0]);
    }

    public function testTheChainStopsAtTheFirstBrokenLevel()
    {
        $missing = $this->chainMissing([Meta::PAR_EXERCISE_ID => '9999', Meta::PAR_GOAL_ID => '9998', Meta::PAR_ACTIVITY_ID => '9997']);

        $this->assertSame([Meta::PAR_EXERCISE_ID], array_keys($missing));
    }

    public function testAnInvalidIdIsLeftToTheIdRuleAndTheChainIsNotChecked()
    {
        $missing = $this->chainMissing([Meta::PAR_GOAL_ID => 'abc']);

        $this->assertSame([Meta::PAR_GOAL_ID], array_keys($missing));
        $this->assertStringContainsString('não guarda um id do PAR', $missing[Meta::PAR_GOAL_ID][0]);
    }

    public function testEveryMissingFieldIsNamedInItsMessage()
    {
        $missing = $this->missing([
            'numberVacancies' => null,
            Meta::TOTAL_RESOURCE => null,
            Meta::PROPONENT_TYPES => null,
            'shortDescription' => '',
            'registrationFrom' => null,
            'registrationTo' => null,
            Meta::PAR_EXERCISE_ID => null,
            Meta::PAR_GOAL_ID => null,
            Meta::PAR_ACTION_ID => null,
            Meta::PAR_ACTIVITY_ID => null,
            Meta::EXECUTION_TYPE => null,
            Meta::SEGMENTS => null,
            Meta::CULTURAL_STAGES => null,
            Meta::THEMATIC_AGENDAS => null,
            Meta::PRIORITY_TERRITORIES => null,
            Meta::FUNDING_SOURCES => null,
            Meta::QUOTA_RESERVATION => null,
            Meta::REGISTRATION_CHANNELS => null,
            Meta::AFFIRMATIVE_ACTIONS => null,
        ], false);

        $expected = [
            'numberVacancies' => 'Total de vagas',
            Meta::TOTAL_RESOURCE => 'Valor total do edital',
            Meta::PROPONENT_TYPES => 'Tipos de proponente',
            'rules' => 'regulamento',
            'shortDescription' => 'Descrição curta',
            'registrationFrom' => 'Data inicial',
            'registrationTo' => 'Data final',
            Meta::PAR_EXERCISE_ID => 'Exercício do PAR',
            Meta::PAR_GOAL_ID => 'Meta do PAR',
            Meta::PAR_ACTION_ID => 'Ação do PAR',
            Meta::PAR_ACTIVITY_ID => 'Atividade do PAR',
            Meta::EXECUTION_TYPE => 'Tipo de Edital',
            Meta::SEGMENTS => 'Segmento artístico-cultural',
            Meta::CULTURAL_STAGES => 'Etapa do fazer cultural',
            Meta::THEMATIC_AGENDAS => 'Pauta temática',
            Meta::PRIORITY_TERRITORIES => 'Território',
            Meta::FUNDING_SOURCES => 'recursos de outras fontes',
            Meta::QUOTA_RESERVATION => 'Reserva de vagas',
            Meta::REGISTRATION_CHANNELS => 'Formas de inscrição',
            Meta::AFFIRMATIVE_ACTIONS => 'ações afirmativas',
        ];

        $this->assertSame(array_keys($expected), array_keys($missing));

        foreach ($expected as $key => $name) {
            $this->assertStringContainsString($name, implode(' ', $missing[$key]), $key);
        }
    }

    public function testAPartialParSelectionDoesNotPublishAndNamesEveryMissingLevel()
    {
        $missing = $this->missing([Meta::PAR_ACTION_ID => null, Meta::PAR_ACTIVITY_ID => '']);

        $this->assertSame([Meta::PAR_ACTION_ID, Meta::PAR_ACTIVITY_ID], array_keys($missing));
        $this->assertStringContainsString('Ação do PAR', implode(' ', $missing[Meta::PAR_ACTION_ID]));
        $this->assertStringContainsString('Atividade do PAR', implode(' ', $missing[Meta::PAR_ACTIVITY_ID]));
    }

    public function testParIdMustBeDigitsSoThePayloadDoesNotSendADifferentValue()
    {
        $this->assertArrayHasKey(Meta::PAR_GOAL_ID, $this->missing([Meta::PAR_GOAL_ID => 'abc']));
        $this->assertArrayHasKey(Meta::PAR_GOAL_ID, $this->missing([Meta::PAR_GOAL_ID => '3.5']));
        $this->assertArrayHasKey(Meta::PAR_GOAL_ID, $this->missing([Meta::PAR_GOAL_ID => '-1']));
        $this->assertSame([], $this->missing([Meta::PAR_GOAL_ID => ' 3 ']));
    }

    public function testParMessagesReadAsSentencesWithTheArticleOfTheName()
    {
        $missing = $this->missing([Meta::PAR_EXERCISE_ID => '', Meta::PAR_GOAL_ID => null, Meta::PAR_ACTION_ID => 'abc']);

        $this->assertSame(['O Exercício do PAR é obrigatório.'], $missing[Meta::PAR_EXERCISE_ID]);
        $this->assertSame(['A Meta do PAR é obrigatória.'], $missing[Meta::PAR_GOAL_ID]);
        $this->assertSame(['A Ação do PAR não guarda um id do PAR: refaça a seleção.'], $missing[Meta::PAR_ACTION_ID]);
    }

    // o editable.js põe a mensagem num atributo title sem escapar: uma aspas dupla corta o texto na metade
    public function testNoMessageHasAnAsciiDoubleQuoteThatWouldCutTheTooltip()
    {
        $missing = $this->missing(array_fill_keys([
            'numberVacancies', Meta::TOTAL_RESOURCE, Meta::PROPONENT_TYPES, 'shortDescription', 'registrationFrom',
            'registrationTo', Meta::EXECUTION_TYPE, Meta::SEGMENTS, Meta::CULTURAL_STAGES, Meta::THEMATIC_AGENDAS,
            Meta::PRIORITY_TERRITORIES, Meta::FUNDING_SOURCES, Meta::QUOTA_RESERVATION, Meta::REGISTRATION_CHANNELS,
            Meta::AFFIRMATIVE_ACTIONS, Meta::PAR_EXERCISE_ID, Meta::PAR_GOAL_ID, Meta::PAR_ACTION_ID, Meta::PAR_ACTIVITY_ID,
        ], null), false);

        $this->assertNotEmpty($missing);
        foreach ($missing as $key => $messages) {
            foreach ($messages as $message) {
                $this->assertStringNotContainsString('"', $message, $key);
            }
        }
    }

    public function testAParIdAboveTheIntegerLimitIsRejectedBeforeThePayloadTruncatesIt()
    {
        $this->assertArrayHasKey(Meta::PAR_GOAL_ID, $this->missing([Meta::PAR_GOAL_ID => '9223372036854775808']));
        $this->assertSame([], $this->missing([Meta::PAR_GOAL_ID => '9223372036854775807']));
    }

    public function testLongDescriptionSubstitutesShortDescription()
    {
        $this->assertSame([], $this->missing(['shortDescription' => '', 'longDescription' => 'Descrição longa']));
    }

    public function testZeroOrNegativeAmountsAreRejected()
    {
        $this->assertArrayHasKey('numberVacancies', $this->missing(['numberVacancies' => '0']));
        $this->assertArrayHasKey(Meta::TOTAL_RESOURCE, $this->missing([Meta::TOTAL_RESOURCE => '-5']));
        $this->assertArrayHasKey(Meta::TOTAL_RESOURCE, $this->missing([Meta::TOTAL_RESOURCE => 'abc']));
    }

    public function testEveryApplicableQuotaNeedsANonNegativeAmountNotOnlyTheLegalOnes()
    {
        // a ampla e as extras também saem no payload; valor nulo viraria 0.00 sem aviso
        $rows = $this->complete()[Meta::QUOTA_RESERVATION];
        $rows[3]['valorDestinado'] = null;
        $this->assertArrayHasKey(Meta::QUOTA_RESERVATION, $this->missing([Meta::QUOTA_RESERVATION => $rows]));

        $rows = $this->complete()[Meta::QUOTA_RESERVATION];
        $rows[3]['valorDestinado'] = -10;
        $this->assertArrayHasKey(Meta::QUOTA_RESERVATION, $this->missing([Meta::QUOTA_RESERVATION => $rows]));

        // cota extra marcada como não aplicável não exige valor
        $rows = $this->complete()[Meta::QUOTA_RESERVATION];
        $rows[] = ['label' => 'Extra', 'vagas' => 0, 'valorDestinado' => null, 'naoAplicavel' => true];
        $this->assertArrayNotHasKey(Meta::QUOTA_RESERVATION, $this->missing([Meta::QUOTA_RESERVATION => $rows]));
    }

    public function testVacanciesMustBeAWholeNumberSoThePayloadSendsTheSameValue()
    {
        // o payload envia (int): "1.500" iria como 1 e "0.5" como 0, então a validação recusa os dois
        $this->assertArrayHasKey('numberVacancies', $this->missing(['numberVacancies' => '1.500']));
        $this->assertArrayHasKey('numberVacancies', $this->missing(['numberVacancies' => '0.5']));
        $this->assertArrayHasKey('numberVacancies', $this->missing(['numberVacancies' => '1500,00']));
        $this->assertArrayNotHasKey('numberVacancies', $this->missing(['numberVacancies' => '1500']));
        $this->assertArrayNotHasKey('numberVacancies', $this->missing(['numberVacancies' => 1]));
    }

    public function testBrazilianAndPlainAmountFormatsAreAccepted()
    {
        foreach (['1.500.000,00', '1500000,50', '1,5', '1500000.50', '1.500.000', '1500000'] as $amount) {
            $this->assertArrayNotHasKey(Meta::TOTAL_RESOURCE, $this->missing([Meta::TOTAL_RESOURCE => $amount]), $amount);
        }
        $this->assertArrayHasKey(Meta::TOTAL_RESOURCE, $this->missing([Meta::TOTAL_RESOURCE => '0,00']));
        $this->assertArrayHasKey(Meta::TOTAL_RESOURCE, $this->missing([Meta::TOTAL_RESOURCE => '0.000,00']));
    }

    public function testUnknownChoiceIsNamedAndDoesNotCountAsAnswer()
    {
        $missing = $this->missing([Meta::PROPONENT_TYPES => 'inexistente']);

        $this->assertStringContainsString('inexistente', implode(' ', $missing[Meta::PROPONENT_TYPES]));
        $this->assertCount(2, $missing[Meta::PROPONENT_TYPES]);
    }

    public function testOtherOptionRequiresItsSpecification()
    {
        $missing = $this->missing([Meta::SEGMENTS => 'Outros', Meta::SEGMENTS_OTHER => ' ']);

        $this->assertSame([Meta::SEGMENTS_OTHER], array_keys($missing));
        $this->assertSame([], $this->missing([Meta::SEGMENTS => 'Outros', Meta::SEGMENTS_OTHER => 'Circo']));
    }

    public function testNotTargetedCannotBeCombinedWithASpecificChoice()
    {
        $missing = $this->missing([Meta::SEGMENTS => '__edital_nao_se_direciona__;Música']);

        $this->assertSame([Meta::SEGMENTS], array_keys($missing));
        $this->assertStringContainsString('não pode ser combinada', implode(' ', $missing[Meta::SEGMENTS]));
        $this->assertSame([], $this->missing([Meta::SEGMENTS => 'Música;Teatro']));
    }

    public function testEveryOtherFundingSourceNeedsAName()
    {
        $sources = [['nomeFonte' => 'Fundo X', 'valor' => 1], ['nomeFonte' => ' ', 'valor' => 2]];
        $missing = $this->missing([Meta::FUNDING_SOURCES => ['houveUtilizacao' => 'sim', 'outrasFontes' => $sources]]);

        $this->assertStringContainsString('todas as fontes', implode(' ', $missing[Meta::FUNDING_SOURCES]));
    }

    public function testQuotaSlotsMustBeNonNegativeWholeNumbersInEveryRow()
    {
        $rows = $this->complete()[Meta::QUOTA_RESERVATION];
        $rows[] = ['label' => 'Extra', 'vagas' => -2, 'valorDestinado' => 0, 'naoAplicavel' => false];
        $rows[3]['vagas'] = 7;
        $this->assertArrayHasKey(Meta::QUOTA_RESERVATION, $this->missing([Meta::QUOTA_RESERVATION => $rows]));

        $rows = $this->complete()[Meta::QUOTA_RESERVATION];
        $rows[0]['vagas'] = 3.5;
        $rows[3]['vagas'] = 4.5;
        $missing = $this->missing([Meta::QUOTA_RESERVATION => $rows]);
        $this->assertStringContainsString('deve ser inteiro', implode(' ', $missing[Meta::QUOTA_RESERVATION]));
    }

    public function testChannelEntriesThatAreNotObjectsAreReported()
    {
        $channels = ['previstasNoEdital' => 'sim', 'formas' => ['texto solto', ['tipo' => 'presencial', 'descricao' => 'Balcão']]];

        $this->assertArrayHasKey(Meta::REGISTRATION_CHANNELS, $this->missing([Meta::REGISTRATION_CHANNELS => $channels]));
    }

    public function testNotPlannedAffirmativeActionIsExclusive()
    {
        $block = ['opcoes' => ['nao_previstas', 'bonus_agentes'], 'bonus_agentes' => ['pessoas_negras']];
        $missing = $this->missing([Meta::AFFIRMATIVE_ACTIONS => $block]);

        $this->assertStringContainsString('não pode ser combinada', implode(' ', $missing[Meta::AFFIRMATIVE_ACTIONS]));
    }

    public function testFundingBlock()
    {
        $this->assertArrayHasKey(Meta::FUNDING_SOURCES, $this->missing([Meta::FUNDING_SOURCES => ['houveUtilizacao' => 'sim', 'outrasFontes' => null]]));
        $this->assertArrayHasKey(Meta::FUNDING_SOURCES, $this->missing([Meta::FUNDING_SOURCES => ['houveUtilizacao' => 'sim', 'outrasFontes' => [['nomeFonte' => ' ', 'valor' => 1]]]]));
        $this->assertSame([], $this->missing([Meta::FUNDING_SOURCES => ['houveUtilizacao' => 'sim', 'recursosProprios' => 0]]));
        $this->assertSame([], $this->missing([Meta::FUNDING_SOURCES => '{"houveUtilizacao":"sim","outrasFontes":[{"nomeFonte":"Fundo X","valor":2}]}']));
    }

    public function testChannelsBlock()
    {
        $yes = ['previstasNoEdital' => 'sim', 'formas' => []];

        $this->assertArrayHasKey(Meta::REGISTRATION_CHANNELS, $this->missing([Meta::REGISTRATION_CHANNELS => $yes]));

        $invalidEmail = ['previstasNoEdital' => 'sim', 'formas' => [['tipo' => 'email', 'descricao' => 'nao-e-email']]];
        $missing = $this->missing([Meta::REGISTRATION_CHANNELS => $invalidEmail]);
        $this->assertStringContainsString('e-mail válido', implode(' ', $missing[Meta::REGISTRATION_CHANNELS]));

        $valid = ['previstasNoEdital' => 'sim', 'formas' => [['tipo' => 'email', 'descricao' => 'edital@cultura.gov.br'], ['tipo' => 'presencial', 'descricao' => 'Balcão']]];
        $this->assertSame([], $this->missing([Meta::REGISTRATION_CHANNELS => $valid]));

        $noDescription = ['previstasNoEdital' => 'sim', 'formas' => [['tipo' => 'presencial', 'descricao' => '']]];
        $this->assertArrayHasKey(Meta::REGISTRATION_CHANNELS, $this->missing([Meta::REGISTRATION_CHANNELS => $noDescription]));
    }

    public function testAffirmativeActionsBlock()
    {
        $this->assertArrayHasKey(Meta::AFFIRMATIVE_ACTIONS, $this->missing([Meta::AFFIRMATIVE_ACTIONS => ['opcoes' => []]]));
        $this->assertArrayHasKey(Meta::AFFIRMATIVE_ACTIONS, $this->missing([Meta::AFFIRMATIVE_ACTIONS => ['opcoes' => ['bonus_agentes']]]));
        $this->assertSame([], $this->missing([Meta::AFFIRMATIVE_ACTIONS => ['opcoes' => ['bonus_agentes'], 'bonus_agentes' => ['pessoas_negras']]]));
        $this->assertArrayHasKey(Meta::AFFIRMATIVE_ACTIONS, $this->missing([Meta::AFFIRMATIVE_ACTIONS => ['opcoes' => ['outra_legislacao']]]));
        $this->assertArrayHasKey(Meta::AFFIRMATIVE_ACTIONS, $this->missing([Meta::AFFIRMATIVE_ACTIONS => ['opcoes' => ['outra_legislacao'], 'outra_legislacao_descricao' => str_repeat('a', 141)]]));
        $this->assertSame([], $this->missing([Meta::AFFIRMATIVE_ACTIONS => ['opcoes' => ['outra_legislacao'], 'outra_legislacao_descricao' => str_repeat('a', 140)]]));
    }

    public function testQuotaBlock()
    {
        $this->assertArrayHasKey(Meta::QUOTA_RESERVATION, $this->missing([Meta::QUOTA_RESERVATION => []]));

        $rows = $this->complete()[Meta::QUOTA_RESERVATION];
        $rows[3]['vagas'] = 9;
        $missing = $this->missing([Meta::QUOTA_RESERVATION => $rows]);
        $this->assertStringContainsString('soma das vagas (14)', implode(' ', $missing[Meta::QUOTA_RESERVATION]));

        $rows = $this->complete()[Meta::QUOTA_RESERVATION];
        $rows[0]['naoAplicavel'] = true;
        $this->assertStringContainsString('Não aplicável', implode(' ', $this->missing([Meta::QUOTA_RESERVATION => $rows])[Meta::QUOTA_RESERVATION]));

        $rows[0]['vagas'] = 0;
        $rows[3]['vagas'] = 8;
        $this->assertSame([], $this->missing([Meta::QUOTA_RESERVATION => $rows]));
    }

    public function testAllLegalQuotasNotApplicableSkipsTheSum()
    {
        $rows = [];

        foreach (['a', 'b', 'c'] as $label) {
            $rows[] = ['label' => $label, 'vagas' => 0, 'valorDestinado' => 0, 'naoAplicavel' => true];
        }

        $rows[] = ['label' => 'Ampla concorrência', 'vagas' => 10, 'valorDestinado' => 0, 'naoAplicavel' => false];

        $this->assertSame([], $this->missing([Meta::QUOTA_RESERVATION => $rows]));
    }
}

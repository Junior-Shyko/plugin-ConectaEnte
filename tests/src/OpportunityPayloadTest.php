<?php

namespace Tests\ConectaEnte;

use ConectaEnte\Metadata\CultBrMetadata as Meta;
use ConectaEnte\Payload\OpportunityPayload;
use ConectaEnte\Services\PublicationRequirements;
use ConectaEnte\Vocabulary\AffirmativeAction;
use ConectaEnte\Vocabulary\AffirmativeActionGroup;
use ConectaEnte\Vocabulary\CulturalStage;
use ConectaEnte\Vocabulary\ExecutionType;
use ConectaEnte\Vocabulary\FundingSource;
use ConectaEnte\Vocabulary\LegalQuota;
use ConectaEnte\Vocabulary\OpportunityStatus;
use ConectaEnte\Vocabulary\PriorityTerritory;
use ConectaEnte\Vocabulary\ProponentType;
use ConectaEnte\Vocabulary\RegistrationChannel;
use ConectaEnte\Vocabulary\Segment;
use ConectaEnte\Vocabulary\TargetingOption;
use ConectaEnte\Vocabulary\ThematicAgenda;
use DateTime;
use PHPUnit\Framework\TestCase;

class OpportunityPayloadTest extends TestCase
{
    /** Uma oportunidade completa, como a leitura do core a entregaria; cada teste sobrescreve o que quer. */
    private function source(array $override = [])
    {
        return array_merge([
            'id' => 42,
            'par' => ['id_exercicio' => '2026', 'id_meta' => '3', 'id_acao' => '4', 'id_atividade' => '5'],
            'name' => 'Edital de Teste 01/2026',
            'executionType' => ExecutionType::CULTURAL_GRANT,
            'status' => OpportunityStatus::ENABLED,
            'publishedAt' => new DateTime('2026-10-01 10:00:00'),
            'shortDescription' => 'Resumo do edital',
            'longDescription' => 'Descrição completa do edital.',
            'numberVacancies' => 10,
            'totalResource' => '150000,00',
            'registrationFrom' => new DateTime('2026-11-01 00:00:00'),
            'registrationTo' => new DateTime('2026-11-30 23:59:59'),
            'proponentTypes' => [ProponentType::INDIVIDUAL],
            'segments' => [Segment::COLLECTIONS],
            'segmentsOther' => '',
            'culturalStages' => [TargetingOption::NOT_TARGETED],
            'culturalStagesOther' => '',
            'thematicAgendas' => [ThematicAgenda::DIGITAL_CULTURE],
            'thematicAgendasOther' => '',
            'priorityTerritories' => [PriorityTerritory::OUTSKIRTS],
            'links' => [['url' => 'https://exemplo.test/edital', 'label' => 'Página do edital']],
            'rulesUrl' => 'https://exemplo.test/regulamento.pdf',
            'fundingSources' => [
                'houveUtilizacao' => PublicationRequirements::NO,
            ],
            'registrationChannels' => [
                'previstasNoEdital' => PublicationRequirements::YES,
                'formas' => [['tipo' => RegistrationChannel::EMAIL, 'descricao' => 'edital@exemplo.test']],
            ],
            'quotaReservation' => [
                ['label' => LegalQuota::BLACK_PEOPLE, 'vagas' => 2, 'valorDestinado' => '30000', 'naoAplicavel' => false],
                ['label' => LegalQuota::INDIGENOUS_PEOPLE, 'vagas' => 0, 'valorDestinado' => '0', 'naoAplicavel' => true],
                ['label' => LegalQuota::PEOPLE_WITH_DISABILITIES, 'vagas' => 0, 'valorDestinado' => '0', 'naoAplicavel' => true],
                ['label' => LegalQuota::OPEN_COMPETITION, 'vagas' => 8, 'valorDestinado' => '120000', 'naoAplicavel' => false],
            ],
            'affirmativeActions' => [
                'opcoes' => [AffirmativeAction::NOT_PLANNED],
                'outra_legislacao_descricao' => '',
            ],
            'cnpj' => '12345678000199',
            'entityName' => 'Prefeitura de Teste',
        ], $override);
    }

    private function payload(array $override = [])
    {
        return (new OpportunityPayload())->assemble($this->source($override));
    }

    public function testTheContractHasTheThirtyFieldsInItsOrder()
    {
        $this->assertCount(30, OpportunityPayload::FIELDS);
        $this->assertSame(OpportunityPayload::FIELDS, array_keys($this->payload()));
    }

    public function testACompleteOpportunityHasNothingMissing()
    {
        $payload = $this->payload();

        $this->assertSame([], (new OpportunityPayload())->missing($payload));
    }

    public function testEachEmptyFieldIsNamedAsMissing()
    {
        $payload = $this->payload([
            'numberVacancies' => null,
            'rulesUrl' => null,
            'name' => '   ',
        ]);

        $this->assertSame(['numero_e_titulo_edital', 'numero_previsto_vagas', 'pdf_edital'], (new OpportunityPayload())->missing($payload));
    }

    public function testMissingNamesAFieldThatIsAbsentFromThePayload()
    {
        $payload = $this->payload();
        unset($payload['valor_total_edital']);

        $this->assertSame(['valor_total_edital'], (new OpportunityPayload())->missing($payload));
    }

    public function testParIdsAreNamedAsMissingWhenEmpty()
    {
        $payload = $this->payload(['par' => ['id_exercicio' => null, 'id_meta' => null, 'id_acao' => null, 'id_atividade' => null]]);

        $this->assertSame(['id_exercicio', 'id_meta', 'id_acao', 'id_atividade'], (new OpportunityPayload())->missing($payload));
    }

    public function testParIdsAreReadFromThePrefixedMetadataKeys()
    {
        $this->assertSame([
            'id_exercicio' => 'conectaente_parExercicioId',
            'id_meta' => 'conectaente_parMetaId',
            'id_acao' => 'conectaente_parAcaoId',
            'id_atividade' => 'conectaente_parAtividadeId',
        ], OpportunityPayload::PAR_METADATA);
    }

    public function testParIdsGoOutAsIntegers()
    {
        $payload = $this->payload();

        $this->assertSame(2026, $payload['id_exercicio']);
        $this->assertSame(5, $payload['id_atividade']);
    }

    public function testParIdIsAnIntegerOnlyWhenItIsDigits()
    {
        $payload = $this->payload(['par' => ['id_exercicio' => 'abc', 'id_meta' => '', 'id_acao' => '12', 'id_atividade' => null]]);

        $this->assertNull($payload['id_exercicio']);
        $this->assertNull($payload['id_meta']);
        $this->assertSame(12, $payload['id_acao']);
        $this->assertNull($payload['id_atividade']);
    }

    public function testTheTotalResourceIsAStringWithTwoDecimals()
    {
        $this->assertSame('150000.00', $this->payload(['totalResource' => '150000,00'])['valor_total_edital']);
        $this->assertSame('1500000.50', $this->payload(['totalResource' => '1.500.000,50'])['valor_total_edital']);
        $this->assertSame('1500.00', $this->payload(['totalResource' => '1.500'])['valor_total_edital']);
        $this->assertNull($this->payload(['totalResource' => 'abc'])['valor_total_edital']);
    }

    public function testVacanciesAreAnInteger()
    {
        $this->assertSame(10, $this->payload(['numberVacancies' => '10'])['numero_previsto_vagas']);
        $this->assertNull($this->payload(['numberVacancies' => null])['numero_previsto_vagas']);
    }

    public function testTheStatusIsIdAndNomeFromTheVocabulary()
    {
        $this->assertSame(['id' => 1, 'nome' => 'Ativado'], $this->payload(['status' => 1])['status']);
    }

    public function testAStatusWithoutLabelIsMissingInsteadOfHavingANullLabel()
    {
        $payload = $this->payload(['status' => 99]);

        $this->assertNull($payload['status']);
        $this->assertSame(['status'], (new OpportunityPayload())->missing($payload));
    }

    public function testExecutionTypeIsTheFixedPortugueseText()
    {
        $this->assertSame(ExecutionType::CULTURAL_GRANT, $this->payload()['forma_de_execucao']);
        $this->assertNull($this->payload(['executionType' => null])['forma_de_execucao']);
    }

    public function testTheDescriptionIsTheLongOneWhenFilledAndIsCutAtSixHundredCharacters()
    {
        $this->assertSame('Descrição completa do edital.', $this->payload()['detalhamento_objeto']);
        $this->assertSame('Resumo do edital', $this->payload(['longDescription' => '  '])['detalhamento_objeto']);

        $long = str_repeat('á', 700);
        $cut = $this->payload(['longDescription' => $long])['detalhamento_objeto'];

        $this->assertSame(600, mb_strlen($cut, 'UTF-8'));
        $this->assertTrue(mb_check_encoding($cut, 'UTF-8'));
    }

    public function testDatesGoOutAsRfc3339WithTheOffset()
    {
        $payload = $this->payload();

        $this->assertSame((new DateTime('2026-10-01 10:00:00'))->format(DateTime::ATOM), $payload['data_publicacao_edital']);
        $this->assertSame((new DateTime('2026-11-01 00:00:00'))->format(DateTime::ATOM), $payload['data_inicial_prazo_inscricao']);
        $this->assertNull($this->payload(['publishedAt' => null])['data_publicacao_edital']);
    }

    public function testProponentTypesKeepOnlyTheContractValues()
    {
        $payload = $this->payload(['proponentTypes' => ProponentType::INDIVIDUAL . ';pessoa_juridica']);

        $this->assertSame([ProponentType::INDIVIDUAL], $payload['tipos_proponentes']);
        $this->assertNull($this->payload(['proponentTypes' => []])['tipos_proponentes']);
    }

    public function testMultiselectGoesOutAsOneTextWithTheLabels()
    {
        $payload = $this->payload(['segments' => [Segment::COLLECTIONS, Segment::AUDIOVISUAL]]);

        $this->assertSame(Segment::text(Segment::COLLECTIONS) . ', ' . Segment::text(Segment::AUDIOVISUAL), $payload['segmentos_artistico_culturais']);
    }

    public function testNotTargetedGoesOutAsItsSentenceAndAllOptionsAsTheWholeVocabulary()
    {
        $this->assertSame(
            'Edital não se direciona a etapa específica',
            $this->payload(['culturalStages' => [TargetingOption::NOT_TARGETED]])['etapas_fazer_cultural']
        );

        $expected = implode(', ', array_map(function ($segment) {
            return Segment::text($segment);
        }, Segment::cases()));

        $this->assertSame($expected, $this->payload(['segments' => [TargetingOption::ALL_OPTIONS]])['segmentos_artistico_culturais']);
    }

    public function testAnEmptyTargetingIsMissing()
    {
        $this->assertNull($this->payload(['priorityTerritories' => []])['recursos_territorios_prioritarios']);
    }

    public function testTheSpecificationIsEmptyWhenTheOptionIsNotChosenAndNullWhenItIsChosenWithoutText()
    {
        $notChosen = $this->payload(['segments' => [Segment::COLLECTIONS], 'segmentsOther' => 'Qualquer coisa']);
        $this->assertSame('', $notChosen['segmento_artistico_cultural_especificar']);

        $chosenWithoutText = $this->payload(['segments' => [Segment::OTHER], 'segmentsOther' => '  ']);
        $this->assertNull($chosenWithoutText['segmento_artistico_cultural_especificar']);
        $this->assertContains('segmento_artistico_cultural_especificar', (new OpportunityPayload())->missing($chosenWithoutText));

        $chosenWithText = $this->payload(['culturalStages' => [CulturalStage::OTHER], 'culturalStagesOther' => ' Oficina ']);
        $this->assertSame('Oficina', $chosenWithText['etapa_fazer_cultural_especificar']);
    }

    public function testFundingSourcesAreMissingWhenNothingWasAnswered()
    {
        $this->assertNull($this->payload(['fundingSources' => []])['recursos_outras_fontes']);
    }

    public function testFundingSourcesGoOutInSnakeCaseWithStringAmounts()
    {
        $funding = $this->payload(['fundingSources' => [
            'houveUtilizacao' => PublicationRequirements::YES,
            FundingSource::OWN_RESOURCES => '1.500,25',
            FundingSource::OTHER_SOURCES => [['nomeFonte' => '  Fundo X ', 'valor' => 10]],
        ]])['recursos_outras_fontes'];

        $this->assertSame('1500.25', $funding['recursos_proprios']);
        $this->assertNull($funding['convenios_parcerias']);
        $this->assertSame([['nome_fonte' => 'Fundo X', 'valor' => '10.00']], $funding['outras_fontes']);
    }

    public function testRegistrationChannelsAreEmptyWhenTheEditalSaysThereAreNone()
    {
        $this->assertSame([], $this->payload(['registrationChannels' => ['previstasNoEdital' => PublicationRequirements::NO]])['tipos_formas_inscricao']);
        $this->assertSame(
            [['tipo' => RegistrationChannel::EMAIL, 'descricao' => 'edital@exemplo.test']],
            $this->payload()['tipos_formas_inscricao']
        );
    }

    public function testQuotasKeepTheLabelAndTheCounts()
    {
        $quotas = $this->payload()['reserva_vagas_cotas'];

        $this->assertCount(4, $quotas);
        $this->assertSame(['label' => LegalQuota::BLACK_PEOPLE, 'vagas' => 2, 'valor_destinado' => '30000.00', 'nao_aplicavel' => false], $quotas[0]);
        $this->assertTrue($quotas[1]['nao_aplicavel']);
        $this->assertNull($this->payload(['quotaReservation' => []])['reserva_vagas_cotas']);
    }

    public function testAffirmativeActionsKeepTheGroupsOfEachOption()
    {
        $actions = $this->payload(['affirmativeActions' => [
            'opcoes' => [AffirmativeAction::AGENT_BONUS],
            'outra_legislacao_descricao' => '',
            AffirmativeAction::AGENT_BONUS => [AffirmativeActionGroup::BLACK_PEOPLE, AffirmativeActionGroup::WOMEN],
        ]])['outras_modalidades_acoes_afirmativas'];

        $this->assertSame([AffirmativeAction::AGENT_BONUS], $actions['opcoes']);
        $this->assertSame([AffirmativeActionGroup::BLACK_PEOPLE, AffirmativeActionGroup::WOMEN], $actions[AffirmativeAction::AGENT_BONUS]);
        // grupos de modalidade não marcada saem vazios, sempre presentes
        $this->assertSame([], $actions[AffirmativeAction::THEME_BONUS]);
    }

    public function testTheFederativeEntityIsCnpjAndNome()
    {
        $this->assertSame(['cnpj' => '12345678000199', 'nome' => 'Prefeitura de Teste'], $this->payload()['ente_federado']);
    }

    public function testEditalCategoriesAreAnEmptyListBecauseTheCoreHasNone()
    {
        $this->assertSame([], $this->payload()['categorias_edital']);
    }

    public function testTheLinksAndThePdfGoOut()
    {
        $payload = $this->payload();

        $this->assertSame([['url' => 'https://exemplo.test/edital', 'label' => 'Página do edital']], $payload['links_da_pagina_pnab']);
        $this->assertSame('https://exemplo.test/regulamento.pdf', $payload['pdf_edital']);
    }

    public function testEveryPossibleStatusHasItsFixedPortugueseLabel()
    {
        foreach (OpportunityStatus::cases() as $status) {
            $payload = OpportunityStatus::toPayload($status);

            $this->assertIsArray($payload, (string) $status);
            $this->assertSame($status, $payload['id']);
            $this->assertNotSame('', $payload['nome']);
        }
    }

    public function testAStatusThatIsNotANumberHasNoPayload()
    {
        $this->assertNull(OpportunityStatus::toPayload(null));
        $this->assertNull(OpportunityStatus::toPayload('ativo'));
    }

    public function testTheMetadataKeyOfThePublicationDateIsOnThePrefix()
    {
        $this->assertStringStartsWith(Meta::PREFIX, Meta::PUBLICATION_DATE);
    }
}

<?php

namespace ConectaEnte\Payload;

use ConectaEnte\Entities\FederativeEntity;
use ConectaEnte\Metadata\CultBrMetadata as Meta;
use ConectaEnte\Services\Money;
use ConectaEnte\Services\PublicationRequirements;
use ConectaEnte\Services\PublicationStamp;
use ConectaEnte\Vocabulary\AffirmativeAction;
use ConectaEnte\Vocabulary\CulturalStage;
use ConectaEnte\Vocabulary\ExecutionType;
use ConectaEnte\Vocabulary\FundingSource;
use ConectaEnte\Vocabulary\OpportunityStatus;
use ConectaEnte\Vocabulary\ProponentType;
use ConectaEnte\Vocabulary\Segment;
use ConectaEnte\Vocabulary\TargetingField;
use ConectaEnte\Vocabulary\TargetingOption;
use ConectaEnte\Vocabulary\ThematicAgenda;
use DateTime;
use DateTimeInterface;
use MapasCulturais\Entities\Opportunity;

/**
 * Monta o payload de 30 campos do edital (ParEditalInMapas) só com o que já está na oportunidade.
 *
 * Campo ausente sai `null` e `missing()` o nomeia, para o gestor corrigir antes de qualquer envio.
 * Nada aqui casa por nome de campo nem usa modelo oficial.
 */
class OpportunityPayload
{
    const DETAIL_MAX_LENGTH = 600;

    /** Os 30 campos do contrato, na ordem do ParEditalInMapas. */
    const FIELDS = [
        'id',
        'id_exercicio',
        'id_meta',
        'id_acao',
        'id_atividade',
        'numero_e_titulo_edital',
        'forma_de_execucao',
        'status',
        'data_publicacao_edital',
        'detalhamento_objeto',
        'numero_previsto_vagas',
        'valor_total_edital',
        'data_inicial_prazo_inscricao',
        'data_final_prazo_inscricao',
        'tipos_proponentes',
        'segmentos_artistico_culturais',
        'segmento_artistico_cultural_especificar',
        'etapas_fazer_cultural',
        'etapa_fazer_cultural_especificar',
        'pautas_especificas',
        'pauta_especifica_especificar',
        'categorias_edital',
        'recursos_territorios_prioritarios',
        'links_da_pagina_pnab',
        'pdf_edital',
        'recursos_outras_fontes',
        'tipos_formas_inscricao',
        'reserva_vagas_cotas',
        'outras_modalidades_acoes_afirmativas',
        'ente_federado',
    ];

    /**
     * Ids do PAR, lidos dos metadados que a #51 registra. Até lá, nulos: o envio real é recusado com
     * 422 e a validação local os nomeia.
     */
    const PAR_METADATA = [
        'id_exercicio' => 'parExercicioId',
        'id_meta' => 'parMetaId',
        'id_acao' => 'parAcaoId',
        'id_atividade' => 'parAtividadeId',
    ];

    /**
     * Monta o payload da oportunidade, que deve estar vinculada a um ente federado.
     *
     * @return array chave do contrato → valor, nulo quando faltar
     */
    public function build(Opportunity $opportunity, FederativeEntity $entity)
    {
        return $this->assemble($this->read($opportunity, $entity));
    }

    /**
     * As chaves do contrato que chegaram nulas ou ausentes, na ordem do contrato.
     *
     * @return string[]
     */
    public function missing(array $payload)
    {
        $missing = [];

        foreach (self::FIELDS as $field) {
            if (!array_key_exists($field, $payload) || $payload[$field] === null) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /**
     * Lê da oportunidade os valores brutos que o payload usa. Nada aqui é mapeado ainda.
     */
    private function read(Opportunity $opportunity, FederativeEntity $entity)
    {
        $par = [];

        foreach (self::PAR_METADATA as $field => $key) {
            $par[$field] = $opportunity->getMetadata($key);
        }

        $links = [];

        foreach ($opportunity->getMetaLists('links') ?: [] as $link) {
            $links[] = ['url' => $link->value, 'label' => $link->title];
        }

        $rules = $opportunity->getFile(PublicationRequirements::RULES_FILE);

        return [
            'id' => $opportunity->id,
            'par' => $par,
            'name' => $opportunity->name,
            'executionType' => $opportunity->{Meta::EXECUTION_TYPE},
            'status' => $opportunity->status,
            'publishedAt' => $this->publishedAt($opportunity),
            'shortDescription' => $opportunity->shortDescription,
            'longDescription' => $opportunity->longDescription,
            'numberVacancies' => $opportunity->{PublicationRequirements::NUMBER_VACANCIES},
            'totalResource' => $opportunity->{Meta::TOTAL_RESOURCE},
            'registrationFrom' => $opportunity->registrationFrom,
            'registrationTo' => $opportunity->registrationTo,
            'proponentTypes' => $opportunity->{Meta::PROPONENT_TYPES},
            'segments' => $opportunity->{Meta::SEGMENTS},
            'segmentsOther' => $opportunity->{Meta::SEGMENTS_OTHER},
            'culturalStages' => $opportunity->{Meta::CULTURAL_STAGES},
            'culturalStagesOther' => $opportunity->{Meta::CULTURAL_STAGES_OTHER},
            'thematicAgendas' => $opportunity->{Meta::THEMATIC_AGENDAS},
            'thematicAgendasOther' => $opportunity->{Meta::THEMATIC_AGENDAS_OTHER},
            'priorityTerritories' => $opportunity->{Meta::PRIORITY_TERRITORIES},
            'links' => $links,
            'rulesUrl' => $rules ? $rules->url : null,
            'fundingSources' => $this->block($opportunity->{Meta::FUNDING_SOURCES}),
            'registrationChannels' => $this->block($opportunity->{Meta::REGISTRATION_CHANNELS}),
            'quotaReservation' => $this->block($opportunity->{Meta::QUOTA_RESERVATION}),
            'affirmativeActions' => $this->block($opportunity->{Meta::AFFIRMATIVE_ACTIONS}),
            'cnpj' => $entity->getCnpj(),
            'entityName' => $entity->getName(),
        ];
    }

    /**
     * Converte os valores lidos no payload do contrato. Recebe só arrays, para ser testável sem banco.
     */
    public function assemble(array $source)
    {
        return [
            'id' => (int) $source['id'],
            'id_exercicio' => $this->parId($source['par']['id_exercicio']),
            'id_meta' => $this->parId($source['par']['id_meta']),
            'id_acao' => $this->parId($source['par']['id_acao']),
            'id_atividade' => $this->parId($source['par']['id_atividade']),
            'numero_e_titulo_edital' => $this->text($source['name']),
            'forma_de_execucao' => $this->executionType($source['executionType']),
            'status' => OpportunityStatus::toPayload($source['status']),
            'data_publicacao_edital' => $this->date($source['publishedAt']),
            'detalhamento_objeto' => $this->objectDetail($source['longDescription'], $source['shortDescription']),
            'numero_previsto_vagas' => is_numeric($source['numberVacancies']) ? (int) $source['numberVacancies'] : null,
            'valor_total_edital' => Money::toDecimal($source['totalResource']),
            'data_inicial_prazo_inscricao' => $this->date($source['registrationFrom']),
            'data_final_prazo_inscricao' => $this->date($source['registrationTo']),
            'tipos_proponentes' => $this->proponentTypes($source['proponentTypes']),
            'segmentos_artistico_culturais' => $this->targeting($source['segments'], TargetingField::SEGMENT),
            'segmento_artistico_cultural_especificar' => $this->specification($source['segments'], $source['segmentsOther'], Segment::OTHER),
            'etapas_fazer_cultural' => $this->targeting($source['culturalStages'], TargetingField::CULTURAL_STAGE),
            'etapa_fazer_cultural_especificar' => $this->specification($source['culturalStages'], $source['culturalStagesOther'], CulturalStage::OTHER),
            'pautas_especificas' => $this->targeting($source['thematicAgendas'], TargetingField::THEMATIC_AGENDA),
            'pauta_especifica_especificar' => $this->specification($source['thematicAgendas'], $source['thematicAgendasOther'], ThematicAgenda::OTHER),
            // o core v5 não tem faixas/linhas de edital: o contrato aceita a lista vazia
            'categorias_edital' => [],
            'recursos_territorios_prioritarios' => $this->targeting($source['priorityTerritories'], TargetingField::PRIORITY_TERRITORY),
            'links_da_pagina_pnab' => $source['links'],
            'pdf_edital' => $this->text($source['rulesUrl']),
            'recursos_outras_fontes' => $this->fundingSources($source['fundingSources']),
            'tipos_formas_inscricao' => $this->registrationChannels($source['registrationChannels']),
            'reserva_vagas_cotas' => $this->quotaReservation($source['quotaReservation']),
            'outras_modalidades_acoes_afirmativas' => $this->affirmativeActions($source['affirmativeActions']),
            'ente_federado' => $this->federativeEntity($source['cnpj'], $source['entityName']),
        ];
    }

    // o campo é aaaa-mm-dd; data inválida digitada à mão sai nula e o payload a nomeia como faltante
    private function publishedAt(Opportunity $opportunity)
    {
        return PublicationStamp::parse($opportunity->getMetadata(Meta::PUBLICATION_DATE));
    }

    private function parId($value)
    {
        $text = (string) $value;

        return ctype_digit($text) ? (int) $text : null;
    }

    private function text($value)
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function date($value)
    {
        return $value instanceof DateTimeInterface ? $value->format(DateTime::ATOM) : null;
    }

    private function executionType($value)
    {
        $value = $this->text($value);

        return $value !== null && ExecutionType::tryFrom($value) !== null ? ExecutionType::text($value) : null;
    }

    // a descrição longa vale quando tem texto; em branco, cai na curta, como na validação de publicação
    private function objectDetail($longDescription, $shortDescription)
    {
        $detail = trim((string) $longDescription) !== '' ? $longDescription : $shortDescription;
        $detail = trim((string) $detail);

        return $detail === '' ? null : mb_substr($detail, 0, self::DETAIL_MAX_LENGTH, 'UTF-8');
    }

    /** Só os tipos que o contrato conhece; a validação de publicação já recusa os outros. */
    private function proponentTypes($value)
    {
        $types = array_values(array_filter(Meta::values($value), function ($type) {
            return ProponentType::tryFrom($type) !== null;
        }));

        return $types ?: null;
    }

    /**
     * Os rótulos escolhidos, unidos por vírgula. "Todas as opções" vira a lista inteira do vocabulário.
     */
    private function targeting($value, $field)
    {
        $values = Meta::values($value);

        if (!$values) {
            return null;
        }

        $vocabulary = TargetingField::vocabulary($field);

        if ($values === [TargetingOption::NOT_TARGETED]) {
            return TargetingField::notTargetedText($field);
        }

        if (in_array(TargetingOption::ALL_OPTIONS, $values, true)) {
            return implode(', ', array_map(function ($option) use ($vocabulary) {
                return $vocabulary::text($option);
            }, $vocabulary::cases()));
        }

        $labels = [];

        foreach ($values as $option) {
            if ($option === TargetingOption::NOT_TARGETED) {
                $labels[] = TargetingField::notTargetedText($field);
            } else {
                // valor fora do vocabulário vai como está; a validação de publicação o recusa antes
                $labels[] = $vocabulary::tryFrom($option) !== null ? $vocabulary::text($option) : $option;
            }
        }

        return implode(', ', $labels);
    }

    /**
     * O "especificar" da opção que pede detalhamento: vazio quando ela não está marcada, nulo quando
     * está marcada e sem texto (o contrato nomeia o campo), e o texto quando preenchido.
     */
    private function specification($mainValue, $detail, $trigger)
    {
        if (!in_array($trigger, Meta::values($mainValue), true)) {
            return '';
        }

        return $this->text($detail);
    }

    /** @return array|null nulo quando o gestor não respondeu o bloco */
    private function fundingSources(array $block)
    {
        if (!$block) {
            return null;
        }

        $sources = [
            'houve_utilizacao' => isset($block['houveUtilizacao']) ? $block['houveUtilizacao'] : null,
            'recursos_proprios' => Money::toDecimal(isset($block[FundingSource::OWN_RESOURCES]) ? $block[FundingSource::OWN_RESOURCES] : null),
            'convenios_parcerias' => Money::toDecimal(isset($block[FundingSource::FEDERATIVE_AGREEMENTS]) ? $block[FundingSource::FEDERATIVE_AGREEMENTS] : null),
            'emendas_parlamentares' => Money::toDecimal(isset($block[FundingSource::PARLIAMENTARY_AMENDMENTS]) ? $block[FundingSource::PARLIAMENTARY_AMENDMENTS] : null),
            'remanescentes_ciclo_1' => Money::toDecimal(isset($block[FundingSource::FIRST_CYCLE_REMAINDER]) ? $block[FundingSource::FIRST_CYCLE_REMAINDER] : null),
            'outras_fontes' => [],
        ];

        foreach ($this->listOf($block, FundingSource::OTHER_SOURCES) as $source) {
            $sources['outras_fontes'][] = [
                'nome_fonte' => trim((string) (isset($source['nomeFonte']) ? $source['nomeFonte'] : '')),
                'valor' => Money::toDecimal(isset($source['valor']) ? $source['valor'] : null) ?: '0.00',
            ];
        }

        return $sources;
    }

    /** @return array|null nulo quando o gestor não respondeu; [] quando respondeu que não há formas */
    private function registrationChannels(array $block)
    {
        if (!$block) {
            return null;
        }

        $answer = isset($block['previstasNoEdital']) ? $block['previstasNoEdital'] : null;

        if ($answer === PublicationRequirements::NO) {
            return [];
        }

        if ($answer !== PublicationRequirements::YES) {
            return null;
        }

        $channels = [];

        foreach ($this->listOf($block, 'formas') as $channel) {
            $channels[] = [
                'tipo' => (string) (isset($channel['tipo']) ? $channel['tipo'] : ''),
                'descricao' => (string) (isset($channel['descricao']) ? $channel['descricao'] : ''),
            ];
        }

        return $channels;
    }

    /** @return array|null */
    private function quotaReservation(array $block)
    {
        $quotas = [];

        foreach ($block as $quota) {
            if (!is_array($quota)) {
                continue;
            }

            $quotas[] = [
                'label' => trim((string) (isset($quota['label']) ? $quota['label'] : '')),
                'vagas' => (int) (isset($quota['vagas']) ? $quota['vagas'] : 0),
                'valor_destinado' => Money::toDecimal(isset($quota['valorDestinado']) ? $quota['valorDestinado'] : null) ?: '0.00',
                'nao_aplicavel' => !empty($quota['naoAplicavel']),
            ];
        }

        return $quotas ?: null;
    }

    /** @return array|null */
    private function affirmativeActions(array $block)
    {
        if (!$block) {
            return null;
        }

        $actions = [
            'opcoes' => $this->strings(isset($block['opcoes']) ? $block['opcoes'] : []),
            'outra_legislacao_descricao' => (string) (isset($block['outra_legislacao_descricao']) ? $block['outra_legislacao_descricao'] : ''),
        ];

        foreach (AffirmativeAction::cases() as $action) {
            if (AffirmativeAction::hasGroups($action)) {
                $actions[$action] = $this->strings(isset($block[$action]) ? $block[$action] : []);
            }
        }

        return $actions;
    }

    /**
     * O contrato pede `cnpj` e `nome`. A forma do campo é pendência com o CultBR (#58): se mudar, muda aqui.
     */
    private function federativeEntity($cnpj, $name)
    {
        return ['cnpj' => $cnpj, 'nome' => $name];
    }

    /** O metadado json volta como array ou texto, conforme o caminho de leitura; aqui vira array. */
    private function block($value)
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        } elseif (is_object($value)) {
            $value = json_decode(json_encode($value), true);
        }

        return is_array($value) ? $value : [];
    }

    private function listOf(array $block, $key)
    {
        return isset($block[$key]) && is_array($block[$key]) ? array_values(array_filter($block[$key], 'is_array')) : [];
    }

    private function strings($values)
    {
        return array_values(array_filter((array) $values, 'is_string'));
    }
}

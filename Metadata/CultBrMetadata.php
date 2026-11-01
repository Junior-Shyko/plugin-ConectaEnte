<?php

namespace ConectaEnte\Metadata;

use ConectaEnte\Services\FundingSourceName;
use ConectaEnte\Vocabulary\CulturalStage;
use ConectaEnte\Vocabulary\ExecutionType;
use ConectaEnte\Vocabulary\PriorityTerritory;
use ConectaEnte\Vocabulary\Segment;
use ConectaEnte\Vocabulary\TargetingField;
use ConectaEnte\Vocabulary\TargetingOption;
use ConectaEnte\Vocabulary\ThematicAgenda;
use MapasCulturais\i;

class CultBrMetadata
{
    const PREFIX = 'conectaente_';

    const EXECUTION_TYPE = 'conectaente_executionType';
    const SEGMENTS = 'conectaente_segments';
    const SEGMENTS_OTHER = 'conectaente_segmentsOther';
    const CULTURAL_STAGES = 'conectaente_culturalStages';
    const CULTURAL_STAGES_OTHER = 'conectaente_culturalStagesOther';
    const THEMATIC_AGENDAS = 'conectaente_thematicAgendas';
    const THEMATIC_AGENDAS_OTHER = 'conectaente_thematicAgendasOther';
    const PRIORITY_TERRITORIES = 'conectaente_priorityTerritories';

    const FUNDING_SOURCES = 'conectaente_fundingSources';
    const QUOTA_RESERVATION = 'conectaente_quotaReservation';
    const REGISTRATION_CHANNELS = 'conectaente_registrationChannels';
    const AFFIRMATIVE_ACTIONS = 'conectaente_affirmativeActions';

    const SEPARATOR = ';';

    private static function specifications()
    {
        return [
            self::SEGMENTS => [self::SEGMENTS_OTHER, Segment::OTHER],
            self::CULTURAL_STAGES => [self::CULTURAL_STAGES_OTHER, CulturalStage::OTHER],
            self::THEMATIC_AGENDAS => [self::THEMATIC_AGENDAS_OTHER, ThematicAgenda::OTHER],
        ];
    }

    private static function labels()
    {
        return [
            self::EXECUTION_TYPE => 'Tipo de Edital',
            self::SEGMENTS => 'Segmento artístico-cultural',
            self::SEGMENTS_OTHER => 'Especificar segmento artístico-cultural',
            self::CULTURAL_STAGES => 'Etapa do fazer cultural',
            self::CULTURAL_STAGES_OTHER => 'Especificar etapa do fazer cultural',
            self::THEMATIC_AGENDAS => 'Pauta temática',
            self::THEMATIC_AGENDAS_OTHER => 'Especificar pauta temática',
            self::PRIORITY_TERRITORIES => 'Território',
            self::FUNDING_SOURCES => 'Houve utilização de recursos de outras fontes?',
            self::QUOTA_RESERVATION => 'Reserva de vagas (cotas)',
            self::REGISTRATION_CHANNELS => 'Formas de inscrição previstas no edital',
            self::AFFIRMATIVE_ACTIONS => 'Outras modalidades de ações afirmativas',
        ];
    }

    public static function definitions()
    {
        $labels = self::labels();

        $definitions = [
            self::EXECUTION_TYPE => [
                'label' => self::t($labels[self::EXECUTION_TYPE]),
                'type' => 'select',
                'options' => ExecutionType::options(),
            ],
        ];

        foreach (TargetingField::cases() as $field) {
            $key = TargetingField::metadataKey($field);

            $definitions[$key] = [
                'label' => self::t($labels[$key]),
                'type' => 'multiselect',
                'options' => self::targetingOptions($field, $field === TargetingField::SEGMENT),
            ];

            if (isset(self::specifications()[$key])) {
                $other = self::specifications()[$key][0];
                $definitions[$other] = ['label' => self::t($labels[$other]), 'type' => 'string'];
            }
        }

        foreach (self::jsonBlocks() as $key => $sanitize) {
            $definitions[$key] = [
                'label' => self::t($labels[$key]),
                'type' => 'json',
                'serialize' => function ($value) use ($sanitize) {
                    return JsonBlock::serialize($value, $sanitize);
                },
                'unserialize' => function ($value) {
                    return JsonBlock::unserialize($value);
                },
            ];
        }

        return $definitions;
    }

    public static function jsonBlocks()
    {
        return [
            self::FUNDING_SOURCES => [new FundingSourceName(), 'sanitizeBlock'],
            self::QUOTA_RESERVATION => null,
            self::REGISTRATION_CHANNELS => null,
            self::AFFIRMATIVE_ACTIONS => null,
        ];
    }

    public static function keys()
    {
        return array_keys(self::definitions());
    }

    public static function register($registrar)
    {
        foreach (self::definitions() as $key => $definition) {
            $registrar->registerOpportunityMetadata($key, $definition);
        }
    }

    public static function values($value)
    {
        if (is_array($value)) {
            $list = $value;
        } elseif (is_string($value) && trim($value) !== '') {
            $list = explode(self::SEPARATOR, $value);
        } else {
            return [];
        }

        $list = array_map(function ($item) {
            return is_scalar($item) ? trim((string) $item) : '';
        }, $list);

        return array_values(array_filter($list, function ($item) {
            return $item !== '';
        }));
    }

    public static function toText($value, $metadataKey = null)
    {
        $field = $metadataKey === null ? null : TargetingField::fromMetadataKey($metadataKey);

        return implode(', ', array_map(function ($item) use ($field) {
            return $field !== null && TargetingOption::isSynthetic($item) ? TargetingOption::text($item, $field) : $item;
        }, self::values($value)));
    }

    public static function missingSpecifications(array $values)
    {
        $missing = [];

        foreach (self::specifications() as $main => $specification) {
            list($specifyKey, $trigger) = $specification;

            $chosen = isset($values[$main]) ? self::values($values[$main]) : [];
            $detail = isset($values[$specifyKey]) && is_scalar($values[$specifyKey]) ? trim((string) $values[$specifyKey]) : '';

            if (in_array($trigger, $chosen, true) && $detail === '') {
                $missing[] = $specifyKey;
            }
        }

        return $missing;
    }

    private static function targetingOptions($field, $includesAllOptions)
    {
        $options = [TargetingOption::NOT_TARGETED => TargetingOption::label(TargetingOption::NOT_TARGETED, $field)];

        if ($includesAllOptions) {
            $options[TargetingOption::ALL_OPTIONS] = TargetingOption::label(TargetingOption::ALL_OPTIONS, $field);
        }

        $vocabulary = TargetingField::vocabulary($field);

        return $options + $vocabulary::options();
    }

    private static function t($text)
    {
        return class_exists(i::class) ? i::__($text) : $text;
    }
}

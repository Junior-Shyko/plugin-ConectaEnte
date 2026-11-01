<?php

namespace ConectaEnte\Metadata;

use ConectaEnte\Services\FundingSourceName;
use ConectaEnte\Vocabulary\AffirmativeAction;
use ConectaEnte\Vocabulary\AffirmativeActionGroup;
use ConectaEnte\Vocabulary\FundingSource;
use ConectaEnte\Vocabulary\LegalQuota;
use ConectaEnte\Vocabulary\RegistrationChannel;
use MapasCulturais\i;

class BlockUiConfig
{
    public static function toArray()
    {
        return [
            'keys' => [
                'funding' => CultBrMetadata::FUNDING_SOURCES,
                'quota' => CultBrMetadata::QUOTA_RESERVATION,
                'channels' => CultBrMetadata::REGISTRATION_CHANNELS,
                'affirmative' => CultBrMetadata::AFFIRMATIVE_ACTIONS,
            ],
            'funding' => [
                'sources' => self::pairs(FundingSource::class, [FundingSource::OTHER_SOURCES]),
                'other' => FundingSource::OTHER_SOURCES,
                'otherLabel' => FundingSource::label(FundingSource::OTHER_SOURCES),
                'nameMax' => FundingSourceName::MAX_LENGTH,
            ],
            'quota' => [
                'legal' => LegalQuota::legal(),
                'open' => LegalQuota::OPEN_COMPETITION,
                // o texto pt-br é o que se grava; o rótulo é só o que se mostra
                'labels' => LegalQuota::options(),
            ],
            'channels' => [
                'channels' => self::pairs(RegistrationChannel::class),
                'email' => RegistrationChannel::EMAIL,
            ],
            'affirmative' => [
                'options' => self::affirmativeOptions(),
                'groups' => self::pairs(AffirmativeActionGroup::class),
                'notPlanned' => ['value' => AffirmativeAction::NOT_PLANNED, 'label' => AffirmativeAction::label(AffirmativeAction::NOT_PLANNED)],
                'other' => AffirmativeAction::OTHER_LEGISLATION,
                'descriptionMax' => AffirmativeAction::otherLegislationMaxLength(),
            ],
            'text' => self::texts(),
        ];
    }

    private static function pairs($vocabulary, array $except = [])
    {
        $pairs = [];

        foreach ($vocabulary::options() as $value => $label) {
            if (!in_array($value, $except, true)) {
                $pairs[] = ['value' => $value, 'label' => $label];
            }
        }

        return $pairs;
    }

    private static function affirmativeOptions()
    {
        $options = [];

        foreach (AffirmativeAction::options() as $value => $label) {
            if (!AffirmativeAction::isExclusive($value)) {
                $options[] = ['value' => $value, 'label' => $label, 'hasGroups' => AffirmativeAction::hasGroups($value)];
            }
        }

        return $options;
    }

    private static function texts()
    {
        return [
            'yes' => i::__('Sim'),
            'no' => i::__('Não'),
            'value' => i::__('Valor (R$)'),
            'remove' => i::__('Remover'),
            'add' => i::__('Incluir'),
            'fundingQuestion' => i::__('Houve utilização de recursos de outras fontes?'),
            'sourceName' => i::__('Nome da fonte'),
            'addSource' => i::__('Incluir fonte'),
            'quotaIntro' => i::__('A ampla concorrência recebe as vagas que sobram do total de vagas do edital.'),
            'quotaLabel' => i::__('Cota'),
            'quotaSlots' => i::__('Vagas'),
            'quotaAmount' => i::__('Valor destinado (R$)'),
            'quotaPercent' => i::__('% das vagas'),
            'notApplicable' => i::__('Não aplicável'),
            'extraQuota' => i::__('Nome da cota extra'),
            'addQuota' => i::__('Incluir cota extra'),
            'totalSlots' => i::__('Total de vagas'),
            'channelsQuestion' => i::__('As formas de inscrição estão previstas no edital?'),
            'channelDescription' => i::__('Descreva'),
            'channelEmail' => i::__('Informe o e-mail'),
            'affirmativeIntro' => i::__('Marque as outras modalidades de ações afirmativas previstas no edital.'),
            'groups' => i::__('Grupos atendidos'),
            'otherDescription' => i::__('Descreva a ação afirmativa'),
        ];
    }
}

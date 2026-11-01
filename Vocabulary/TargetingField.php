<?php

namespace ConectaEnte\Vocabulary;

use ConectaEnte\Metadata\CultBrMetadata;

class TargetingField
{
    const SEGMENT = 'segment';
    const CULTURAL_STAGE = 'cultural_stage';
    const THEMATIC_AGENDA = 'thematic_agenda';
    const PRIORITY_TERRITORY = 'priority_territory';

    public static function cases()
    {
        return [self::SEGMENT, self::CULTURAL_STAGE, self::THEMATIC_AGENDA, self::PRIORITY_TERRITORY];
    }

    public static function fromMetadataKey($metadataKey)
    {
        foreach (self::cases() as $field) {
            if (self::metadataKey($field) === $metadataKey) {
                return $field;
            }
        }

        return null;
    }

    public static function metadataKey($field)
    {
        return self::pick($field, [
            self::SEGMENT => CultBrMetadata::SEGMENTS,
            self::CULTURAL_STAGE => CultBrMetadata::CULTURAL_STAGES,
            self::THEMATIC_AGENDA => CultBrMetadata::THEMATIC_AGENDAS,
            self::PRIORITY_TERRITORY => CultBrMetadata::PRIORITY_TERRITORIES,
        ]);
    }

    public static function vocabulary($field)
    {
        return self::pick($field, [
            self::SEGMENT => Segment::class,
            self::CULTURAL_STAGE => CulturalStage::class,
            self::THEMATIC_AGENDA => ThematicAgenda::class,
            self::PRIORITY_TERRITORY => PriorityTerritory::class,
        ]);
    }

    public static function notTargetedText($field)
    {
        return self::pick($field, [
            self::SEGMENT => 'Edital não se direciona a segmentos específicos',
            self::CULTURAL_STAGE => 'Edital não se direciona a etapa específica',
            self::THEMATIC_AGENDA => 'Edital não se direciona a pautas específicas',
            self::PRIORITY_TERRITORY => 'Edital não se direciona a territórios específicos',
        ]);
    }

    private static function pick($field, array $map)
    {
        if (!isset($map[$field])) {
            throw new \InvalidArgumentException(sprintf('Campo de direcionamento desconhecido: "%s".', $field));
        }

        return $map[$field];
    }
}

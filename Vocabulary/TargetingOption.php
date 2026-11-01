<?php

namespace ConectaEnte\Vocabulary;

use MapasCulturais\i;

class TargetingOption
{
    const NOT_TARGETED = '__edital_nao_se_direciona__';
    const ALL_OPTIONS = '__todas_opcoes__';

    public static function cases()
    {
        return [self::NOT_TARGETED, self::ALL_OPTIONS];
    }

    public static function isSynthetic($value)
    {
        return in_array($value, self::cases(), true);
    }

    public static function text($option, $field)
    {
        if ($option === self::NOT_TARGETED) {
            return TargetingField::notTargetedText($field);
        }

        if ($option === self::ALL_OPTIONS) {
            return 'Todas as opções';
        }

        throw new \InvalidArgumentException(sprintf('Opção sintética desconhecida: "%s".', $option));
    }

    public static function label($option, $field)
    {
        $text = self::text($option, $field);

        return class_exists(i::class) ? i::__($text) : $text;
    }
}

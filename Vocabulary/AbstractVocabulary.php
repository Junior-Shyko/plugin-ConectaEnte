<?php

namespace ConectaEnte\Vocabulary;

use MapasCulturais\i;

abstract class AbstractVocabulary
{
    public static function cases()
    {
        $values = [];

        foreach ((new \ReflectionClass(static::class))->getReflectionConstants() as $constant) {
            if ($constant->isPublic() && $constant->getDeclaringClass()->getName() === static::class) {
                $values[] = $constant->getValue();
            }
        }

        return $values;
    }

    public static function tryFrom($value)
    {
        return in_array($value, static::cases(), true) ? $value : null;
    }

    /**
     * Rótulo legível na língua da instalação; nulo para valor fora da lista, de propósito,
     * para falhar alto em vez de devolver a chave crua.
     *
     * @param mixed $value
     * @return string|null
     */
    public static function label($value)
    {
        $value = static::tryFrom($value);

        if ($value === null) {
            return null;
        }

        return self::translate(static::text($value));
    }

    public static function text($value)
    {
        $texts = static::texts();

        return isset($texts[$value]) ? $texts[$value] : $value;
    }

    public static function options()
    {
        $options = [];

        foreach (static::cases() as $value) {
            $options[$value] = static::label($value);
        }

        return $options;
    }

    protected static function texts()
    {
        return [];
    }

    private static function translate($text)
    {
        return class_exists(i::class) ? i::__($text) : $text;
    }
}

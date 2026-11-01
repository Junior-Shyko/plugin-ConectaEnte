<?php

namespace ConectaEnte\Metadata;

/**
 * Gravação dos blocos JSON da oportunidade. O front da v5 envia cada metadado como texto num `PUT`
 * de formulário, e o serializador `json` do core codificaria esse texto de novo; aqui o texto JSON
 * válido é gravado como veio (normalizado) e uma lista/objeto é codificado.
 */
class JsonBlock
{
    public static function serialize($value, callable $sanitize = null)
    {
        if (is_string($value)) {
            if (trim($value) === '') {
                return null;
            }

            $value = json_decode($value, true);
        } elseif (is_object($value) || is_array($value)) {
            $value = json_decode(json_encode($value), true);
        }

        if (!is_array($value)) {
            return null;
        }

        if ($sanitize !== null) {
            $value = call_user_func($sanitize, $value);
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    public static function unserialize($value)
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }
}

<?php

namespace ConectaEnte\Services;

/**
 * Deixa o que o CultBR devolveu em condição de entrar em coluna JSONB: o Postgres recusa UTF-8 inválido
 * (cabeçalho em latin-1 é o caso comum) e o caractere NUL, e o corpo pode ser arbitrariamente grande.
 */
final class AttemptSanitizer
{
    const MAX_RESPONSE_BYTES = 65536;
    const REPLACEMENT_CODE_POINT = 0xFFFD;

    // bytes inválidos viram U+FFFD (e não "?", o padrão do mbstring), para o texto não parecer ter sido escrito assim
    public static function text($value)
    {
        $value = str_replace("\0", '', (string) $value);

        $previous = mb_substitute_character();
        mb_substitute_character(self::REPLACEMENT_CODE_POINT);

        try {
            return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        } finally {
            mb_substitute_character($previous);
        }
    }

    /**
     * Corpo JSON volta decodificado; o resto (HTML de proxy, texto puro, JSON cortado) volta no envelope
     * `{"raw": ...}`, e `truncated` marca o corte. mb_strcut corta em bytes sem partir um caractere ao meio.
     */
    public static function response($body)
    {
        $body = (string) $body;

        if ($body === '') {
            return null;
        }

        $text = self::text($body);

        if (strlen($text) > self::MAX_RESPONSE_BYTES) {
            return ['raw' => mb_strcut($text, 0, self::MAX_RESPONSE_BYTES, 'UTF-8'), 'truncated' => true];
        }

        $decoded = json_decode($text, true);

        // json_encode de volta pega o "\u0000" escapado, que decodifica sem erro mas o JSONB recusa
        if (is_array($decoded) && strpos(json_encode($decoded), '\u0000') === false) {
            return $decoded;
        }

        return ['raw' => $text];
    }

    public static function headers(array $headers)
    {
        $clean = [];

        foreach ($headers as $name => $value) {
            $clean[self::text(strtolower((string) $name))] = self::text(is_array($value) ? implode(', ', $value) : $value);
        }

        return $clean === [] ? null : $clean;
    }

    // o payload é montado pelo plugin e já passou pelo json_encode do envio; nulo só se ainda assim não serializar
    public static function payloadJson(array $payload)
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

        return $json === false ? null : str_replace('\u0000', '', $json);
    }

    public static function json($value)
    {
        if ($value === null) {
            return null;
        }

        $json = json_encode($value, JSON_UNESCAPED_UNICODE);

        return $json === false ? null : $json;
    }
}

<?php

namespace ConectaEnte\Services;

/**
 * Valor monetário digitado pelo gestor, normalizado para o texto decimal do contrato ("150000.00").
 */
final class Money
{
    /**
     * Formatos aceitos: "1.500.000,00" e "1500000,50" (pt-BR), "1500000.50" e "1500000" (simples),
     * e "1.500" / "1.500.000" como milhar, porque em edital o ponto seguido de três dígitos é milhar.
     *
     * @param mixed $value
     *
     * @return string|null o valor com duas casas, ou nulo quando não é um número
     */
    public static function toDecimal($value)
    {
        if (is_int($value)) {
            return $value >= 0 ? self::fromText((string) $value) : null;
        }

        if (is_float($value)) {
            // float já perdeu dígitos antes de chegar aqui; por isso só o texto é exato
            return is_finite($value) && $value >= 0 ? number_format($value, 2, '.', '') : null;
        }

        if (!is_string($value)) {
            return null;
        }

        $text = trim($value);

        if (strpos($text, ',') !== false) {
            // antes de tirar os pontos: "12.34,56" e "1,000.50" não são agrupamento válido e viram nulo
            if (!preg_match('/^\d{1,3}(\.\d{3})*,\d+$|^\d+,\d+$/', $text)) {
                return null;
            }

            $text = str_replace(',', '.', str_replace('.', '', $text));
        } elseif (preg_match('/^[1-9]\d{0,2}(\.\d{3})+$/', $text)) {
            $text = str_replace('.', '', $text);
        }

        if (!preg_match('/^\d+(\.\d+)?$/', $text)) {
            return null;
        }

        return self::fromText($text);
    }

    /**
     * Formata um número sem sinal, só com dígitos e um ponto opcional, sem passar por float: valores
     * acima de 2^53 mantêm todos os dígitos. Terceira casa decimal em diante é arredondada (meio para cima).
     */
    private static function fromText($text)
    {
        $parts = explode('.', $text, 2);
        $integer = $parts[0];
        $fraction = str_pad(isset($parts[1]) ? $parts[1] : '', 3, '0');

        // os centavos juntos com a parte inteira, como um único número em texto
        $cents = $integer . substr($fraction, 0, 2);

        if ($fraction[2] >= '5') {
            $cents = self::incrementDigits($cents);
        }

        $integer = ltrim(substr($cents, 0, -2), '0');

        return ($integer === '' ? '0' : $integer) . '.' . substr($cents, -2);
    }

    /** Soma 1 a um número escrito em dígitos, sem limite de tamanho. */
    private static function incrementDigits($digits)
    {
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            if ($digits[$i] !== '9') {
                $digits[$i] = chr(ord($digits[$i]) + 1);

                return $digits;
            }

            $digits[$i] = '0';
        }

        return '1' . $digits;
    }
}

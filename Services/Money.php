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
        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value) ? number_format((float) $value, 2, '.', '') : null;
        }

        if (!is_string($value)) {
            return null;
        }

        $text = trim($value);

        if (strpos($text, ',') !== false) {
            $text = str_replace(',', '.', str_replace('.', '', $text));
        } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $text)) {
            $text = str_replace('.', '', $text);
        }

        if (!preg_match('/^\d+(\.\d+)?$/', $text)) {
            return null;
        }

        return number_format((float) $text, 2, '.', '');
    }
}

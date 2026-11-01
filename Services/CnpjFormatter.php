<?php

namespace ConectaEnte\Services;

class CnpjFormatter
{
    public static function format($cnpj)
    {
        $digits = (string) $cnpj;

        if (!preg_match('/^\d{14}\z/', $digits)) {
            return $digits;
        }

        return sprintf(
            '%s.%s.%s/%s-%s',
            substr($digits, 0, 2),
            substr($digits, 2, 3),
            substr($digits, 5, 3),
            substr($digits, 8, 4),
            substr($digits, 12, 2)
        );
    }
}

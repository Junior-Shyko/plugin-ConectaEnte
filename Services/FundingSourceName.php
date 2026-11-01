<?php

namespace ConectaEnte\Services;

use ConectaEnte\Vocabulary\FundingSource;

class FundingSourceName
{
    const MAX_LENGTH = 255;

    public function sanitizeBlock($block)
    {
        if (!is_array($block) || !isset($block[FundingSource::OTHER_SOURCES]) || !is_array($block[FundingSource::OTHER_SOURCES])) {
            return $block;
        }

        foreach ($block[FundingSource::OTHER_SOURCES] as $index => $source) {
            if (is_array($source) && array_key_exists('nomeFonte', $source)) {
                $block[FundingSource::OTHER_SOURCES][$index]['nomeFonte'] = $this->sanitize($source['nomeFonte']);
            }
        }

        return $block;
    }

    public function sanitize($name)
    {
        $name = is_scalar($name) ? trim(strip_tags((string) $name)) : '';

        return mb_substr($name, 0, self::MAX_LENGTH);
    }
}

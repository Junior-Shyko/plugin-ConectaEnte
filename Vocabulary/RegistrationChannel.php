<?php

namespace ConectaEnte\Vocabulary;

class RegistrationChannel extends AbstractVocabulary
{
    const EMAIL = 'email';
    const IN_PERSON = 'presencial';
    const MAIL = 'correio';
    const ORAL = 'oral';
    const SYSTEM = 'sistema';
    const OTHER = 'outros';

    protected static function texts()
    {
        return [
            self::EMAIL => 'E-mail',
            self::IN_PERSON => 'Presencial',
            self::MAIL => 'Correspondência',
            self::ORAL => 'Oralidade',
            self::SYSTEM => 'Sistema digital',
            self::OTHER => 'Outros',
        ];
    }
}

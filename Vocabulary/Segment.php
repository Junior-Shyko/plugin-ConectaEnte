<?php

namespace ConectaEnte\Vocabulary;

class Segment extends AbstractVocabulary
{
    const COLLECTIONS = 'Acervos';
    const ARCHIVES = 'Arquivos';
    const VISUAL_ARTS = 'Artes Visuais';
    const HANDICRAFT = 'Artesanato';
    const AUDIOVISUAL = 'Audiovisual';
    const CAPOEIRA = 'Capoeira';
    const CIRCUS = 'Circo';
    const AFRICAN_ROOTED_CULTURE = 'Cultura de Matriz Africana';
    const NATIVE_PEOPLES_CULTURE = 'Cultura dos Povos Originários';
    const TRADITIONAL_AND_POPULAR_CULTURES = 'Culturas Tradicionais e Populares';
    const DANCE = 'Dança';
    const DESIGN = 'Design';
    const PUBLISHING = 'Edição e produção editorial';
    const FESTIVITIES_AND_CELEBRATIONS = 'Festas e Celebrações';
    const HIP_HOP = 'Hip Hop';
    const VIDEO_GAMES = 'Jogos eletrônicos';
    const LITERATURE = 'Literatura';
    const READER_EDUCATION = 'Mediação e formação de leitores';
    const FASHION = 'Moda';
    const MUSEUM = 'Museu';
    const MUSIC = 'Música';
    const ARCHAEOLOGICAL_HERITAGE = 'Patrimônio Arqueológico';
    const TANGIBLE_CULTURAL_HERITAGE = 'Patrimônio Cultural Material';
    const INTANGIBLE_CULTURAL_HERITAGE = 'Patrimônio Cultural Imaterial';
    const NATURAL_HERITAGE = 'Patrimônio Natural';
    const PERFORMANCE = 'Performance';
    const THEATER = 'Teatro';
    const OTHER = 'Outros';

    protected static function texts()
    {
        return [self::OTHER => 'Outros (especificar)'];
    }
}

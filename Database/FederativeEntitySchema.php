<?php

namespace ConectaEnte\Database;

class FederativeEntitySchema
{
    const TABLE = 'conectaente_federative_entity';
    const SEQUENCE = 'conectaente_federative_entity_id_seq';
    const UNIQUE_SEAL = 'conectaente_federative_entity_seal_id_uq';
    const FK_SEAL = 'conectaente_federative_entity_seal_fk';

    // nomes em português das instalações que criaram a tabela antes da troca para inglês
    const LEGACY_TABLE = 'conectaente_ente_federado';
    const LEGACY_SEQUENCE = 'conectaente_ente_federado_id_seq';
    const LEGACY_PRIMARY_KEY = 'conectaente_ente_federado_pkey';
    const LEGACY_UNIQUE_SEAL = 'conectaente_ente_federado_seal_id_uq';
    const LEGACY_FK_SEAL = 'conectaente_ente_federado_seal_fk';

    public static function renameLegacyStatements()
    {
        return [
            'ALTER TABLE ' . self::LEGACY_TABLE . ' RENAME TO ' . self::TABLE,
            'ALTER SEQUENCE ' . self::LEGACY_SEQUENCE . ' RENAME TO ' . self::SEQUENCE,
            'ALTER TABLE ' . self::TABLE . ' RENAME CONSTRAINT ' . self::LEGACY_PRIMARY_KEY . ' TO ' . self::TABLE . '_pkey',
            'ALTER TABLE ' . self::TABLE . ' RENAME CONSTRAINT ' . self::LEGACY_UNIQUE_SEAL . ' TO ' . self::UNIQUE_SEAL,
            'ALTER TABLE ' . self::TABLE . ' RENAME CONSTRAINT ' . self::LEGACY_FK_SEAL . ' TO ' . self::FK_SEAL,
        ];
    }

    public static function createSequence()
    {
        return 'CREATE SEQUENCE ' . self::SEQUENCE . ' INCREMENT BY 1 MINVALUE 1 START 1';
    }

    // a FK com CASCADE faz o ente sair junto quando o selo é excluído (só admin exclui selo)
    public static function createTable()
    {
        return 'CREATE TABLE ' . self::TABLE . ' (
            id INT NOT NULL,
            name VARCHAR(255) DEFAULT NULL,
            seal_id INT NOT NULL,
            token TEXT NOT NULL,
            cnpj VARCHAR(14) DEFAULT NULL,
            create_timestamp TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            update_timestamp TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            PRIMARY KEY(id),
            CONSTRAINT ' . self::UNIQUE_SEAL . ' UNIQUE (seal_id),
            CONSTRAINT ' . self::FK_SEAL . ' FOREIGN KEY (seal_id) REFERENCES seal (id)
                ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        )';
    }
}

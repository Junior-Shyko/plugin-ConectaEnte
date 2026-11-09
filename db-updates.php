<?php

use ConectaEnte\Database\FederativeEntitySchema;
use ConectaEnte\Database\SendLogSchema;
use ConectaEnte\Metadata\CultBrMetadata;
use ConectaEnte\Migrations\ClaimParEditalId;

use function MapasCulturais\__column_exists;
use function MapasCulturais\__exec;
use function MapasCulturais\__sequence_exists;
use function MapasCulturais\__table_exists;

// as chaves são globais na aplicação (migração de mesmo nome é descartada): sempre com prefixo conectaente
return [
    // vem antes da criação: quem já tem a tabela com nome em português a renomeia em vez de criar outra
    'conectaente: rename ente_federado table to federative_entity' => function () {
        if (__table_exists(FederativeEntitySchema::LEGACY_TABLE) && !__table_exists(FederativeEntitySchema::TABLE)) {
            foreach (FederativeEntitySchema::renameLegacyStatements() as $sql) {
                __exec($sql);
            }
        }

        // a coluna `nome` também já existiu: renomeia onde a tabela a tenha
        if (__column_exists(FederativeEntitySchema::TABLE, 'nome') && !__column_exists(FederativeEntitySchema::TABLE, 'name')) {
            __exec('ALTER TABLE ' . FederativeEntitySchema::TABLE . ' RENAME COLUMN nome TO name');
        }
    },

    'conectaente: create federative_entity table' => function () {
        if (!__sequence_exists(FederativeEntitySchema::SEQUENCE)) {
            __exec(FederativeEntitySchema::createSequence());
        }

        if (!__table_exists(FederativeEntitySchema::TABLE)) {
            __exec(FederativeEntitySchema::createTable());
        }
    },

    'conectaente: create send_log tables' => function () {
        if (!__sequence_exists(SendLogSchema::LOG_SEQUENCE)) {
            __exec(SendLogSchema::createLogSequence());
        }

        if (!__sequence_exists(SendLogSchema::ATTEMPT_SEQUENCE)) {
            __exec(SendLogSchema::createAttemptSequence());
        }

        if (!__table_exists(SendLogSchema::LOG_TABLE)) {
            __exec(SendLogSchema::createLogTable());
            __exec(SendLogSchema::createLogIndex());
        }

        if (!__table_exists(SendLogSchema::ATTEMPT_TABLE)) {
            __exec(SendLogSchema::createAttemptTable());

            foreach (SendLogSchema::createAttemptIndexes() as $sql) {
                __exec($sql);
            }
        }
    },

    // a limpeza vem antes do índice, no mesmo passo: índice único não nasce com acervo duplicado
    'conectaente: one opportunity per par_edital_id' => function () {
        ClaimParEditalId::run();

        __exec('DROP INDEX IF EXISTS ' . SendLogSchema::UNIQUE_PAR_EDITAL_OWNER);
        __exec(SendLogSchema::createParEditalOwnerIndex(CultBrMetadata::PAR_EDITAL_ID));
    },
];

<?php

namespace ConectaEnte\Database;

/**
 * Histórico de envios (#54): um envio tem N tentativas.
 *
 * Sem FK para `opportunity` de propósito: o histórico sobrevive à exclusão da oportunidade, e uma FK
 * faria essa exclusão falhar (ou apagar o histórico junto).
 */
class SendLogSchema
{
    const LOG_TABLE = 'conectaente_send_log';
    const LOG_SEQUENCE = 'conectaente_send_log_id_seq';
    const ATTEMPT_TABLE = 'conectaente_send_log_attempt';
    const ATTEMPT_SEQUENCE = 'conectaente_send_log_attempt_id_seq';

    const UNIQUE_REQUEST_UUID = 'conectaente_send_log_request_uuid_uq';
    const FK_USER = 'conectaente_send_log_user_fk';
    const FK_LOG = 'conectaente_send_log_attempt_log_fk';
    const INDEX_OPPORTUNITY = 'conectaente_send_log_opportunity_idx';
    const INDEX_LOG = 'conectaente_send_log_attempt_log_idx';
    const INDEX_PAR_EDITAL = 'conectaente_send_log_attempt_par_edital_idx';
    const INDEX_REQUEST_ID = 'conectaente_send_log_attempt_request_id_idx';

    // um id_par_edital pertence a uma oportunidade só; o reenvio da mesma oportunidade repete o id
    const UNIQUE_PAR_EDITAL_OWNER = 'unq_conectaente_par_edital_id';

    public static function createLogSequence()
    {
        return 'CREATE SEQUENCE ' . self::LOG_SEQUENCE . ' INCREMENT BY 1 MINVALUE 1 START 1';
    }

    public static function createAttemptSequence()
    {
        return 'CREATE SEQUENCE ' . self::ATTEMPT_SEQUENCE . ' INCREMENT BY 1 MINVALUE 1 START 1';
    }

    // a FK do usuário é SET NULL: apagar o usuário não apaga o histórico
    public static function createLogTable()
    {
        return 'CREATE TABLE ' . self::LOG_TABLE . ' (
            id INT NOT NULL,
            request_uuid VARCHAR(36) NOT NULL,
            opportunity_id INT NOT NULL,
            action VARCHAR(32) NOT NULL,
            status VARCHAR(16) NOT NULL,
            user_id INT DEFAULT NULL,
            create_timestamp TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            update_timestamp TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            PRIMARY KEY(id),
            CONSTRAINT ' . self::UNIQUE_REQUEST_UUID . ' UNIQUE (request_uuid),
            CONSTRAINT ' . self::FK_USER . ' FOREIGN KEY (user_id) REFERENCES usr (id)
                ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE
        )';
    }

    // não há coluna de cabeçalho de requisição: é isso que mantém o token fora do banco
    public static function createAttemptTable()
    {
        return 'CREATE TABLE ' . self::ATTEMPT_TABLE . ' (
            id INT NOT NULL,
            log_id INT NOT NULL,
            attempt INT NOT NULL,
            max_attempts INT NOT NULL,
            endpoint TEXT DEFAULT NULL,
            http_method VARCHAR(8) DEFAULT NULL,
            http_status INT DEFAULT NULL,
            payload JSONB DEFAULT NULL,
            response JSONB DEFAULT NULL,
            response_headers JSONB DEFAULT NULL,
            par_edital_id VARCHAR(64) DEFAULT NULL,
            request_id VARCHAR(255) DEFAULT NULL,
            error_message TEXT DEFAULT NULL,
            status VARCHAR(16) NOT NULL,
            sent_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            duration_ms INT DEFAULT NULL,
            PRIMARY KEY(id),
            CONSTRAINT ' . self::FK_LOG . ' FOREIGN KEY (log_id) REFERENCES ' . self::LOG_TABLE . ' (id)
                ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        )';
    }

    public static function createLogIndex()
    {
        return 'CREATE INDEX ' . self::INDEX_OPPORTUNITY . ' ON ' . self::LOG_TABLE . ' (opportunity_id, status)';
    }

    public static function createAttemptIndexes()
    {
        return [
            'CREATE INDEX ' . self::INDEX_LOG . ' ON ' . self::ATTEMPT_TABLE . ' (log_id)',
            'CREATE INDEX ' . self::INDEX_PAR_EDITAL . ' ON ' . self::ATTEMPT_TABLE . ' (par_edital_id)',
            'CREATE INDEX ' . self::INDEX_REQUEST_ID . ' ON ' . self::ATTEMPT_TABLE . ' (request_id)',
        ];
    }

    // índice parcial sobre o metadado que o envio aceito grava: o banco garante um dono por edital do CultBR.
    // Valor vazio fica de fora: metadado limpo pela API não é id, e duas oportunidades podem tê-lo vazio
    public static function createParEditalOwnerIndex($metadataKey)
    {
        return 'CREATE UNIQUE INDEX ' . self::UNIQUE_PAR_EDITAL_OWNER . " ON opportunity_meta (value) WHERE key = '" . $metadataKey . "' AND value <> ''";
    }
}

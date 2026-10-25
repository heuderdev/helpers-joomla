CREATE TABLE IF NOT EXISTS "#__queue_jobs" (
    "id" BIGSERIAL NOT NULL,
    "uuid" CHAR(36) NOT NULL,
    "queue" VARCHAR(100) NOT NULL DEFAULT 'default',
    "tipo" VARCHAR(150) NOT NULL,
    "payload" TEXT NULL,
    "status" VARCHAR(30) NOT NULL DEFAULT 'pending',
    "prioridade" INTEGER NOT NULL DEFAULT 0,
    "progresso" BIGINT NOT NULL DEFAULT 0,
    "total" BIGINT NOT NULL DEFAULT 0,
    "percentual" NUMERIC(7,4) NOT NULL DEFAULT 0,
    "tentativas" INTEGER NOT NULL DEFAULT 0,
    "max_tentativas" INTEGER NOT NULL DEFAULT 3,
    "timeout_seconds" INTEGER NOT NULL DEFAULT 240,
    "retry_after_seconds" INTEGER NOT NULL DEFAULT 300,
    "disponivel_em" TIMESTAMP WITHOUT TIME ZONE NOT NULL,
    "iniciado_em" TIMESTAMP WITHOUT TIME ZONE NULL,
    "finalizado_em" TIMESTAMP WITHOUT TIME ZONE NULL,
    "lock_token" VARCHAR(100) NULL,
    "lock_em" TIMESTAMP WITHOUT TIME ZONE NULL,
    "last_heartbeat_at" TIMESTAMP WITHOUT TIME ZONE NULL,
    "started_by" VARCHAR(150) NULL,
    "usuario_id" INTEGER NULL,
    "resultado" TEXT NULL,
    "erro" TEXT NULL,
    "criado_em" TIMESTAMP WITHOUT TIME ZONE NOT NULL,
    "atualizado_em" TIMESTAMP WITHOUT TIME ZONE NOT NULL,
    PRIMARY KEY ("id")
);

CREATE UNIQUE INDEX IF NOT EXISTS "#__queue_jobs_idx_uuid" ON "#__queue_jobs" ("uuid");
CREATE INDEX IF NOT EXISTS "#__queue_jobs_idx_dispatch" ON "#__queue_jobs" ("queue", "status", "disponivel_em", "prioridade", "id");
CREATE INDEX IF NOT EXISTS "#__queue_jobs_idx_status" ON "#__queue_jobs" ("status", "disponivel_em", "prioridade", "id");
CREATE INDEX IF NOT EXISTS "#__queue_jobs_idx_tipo" ON "#__queue_jobs" ("tipo", "status", "disponivel_em");
CREATE INDEX IF NOT EXISTS "#__queue_jobs_idx_usuario" ON "#__queue_jobs" ("usuario_id", "criado_em");
CREATE INDEX IF NOT EXISTS "#__queue_jobs_idx_finalizado" ON "#__queue_jobs" ("finalizado_em");

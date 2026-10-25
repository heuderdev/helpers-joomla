-- RateLimitHelper: contadores de tentativas (PostgreSQL 9.5+).
CREATE TABLE IF NOT EXISTS "#__helpers_rate_limits" (
    chave CHAR(40) NOT NULL PRIMARY KEY,
    hits INTEGER NOT NULL DEFAULT 0,
    expires_at BIGINT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_helpers_rate_limits_expires ON "#__helpers_rate_limits" (expires_at);

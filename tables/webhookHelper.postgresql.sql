-- WebhookHelper: eventos recebidos (PostgreSQL).
CREATE TABLE IF NOT EXISTS "#__helpers_webhooks" (
    id BIGSERIAL PRIMARY KEY,
    provider VARCHAR(50) NOT NULL,
    event_id VARCHAR(191) NOT NULL,
    event_type VARCHAR(100) NOT NULL DEFAULT '',
    payload TEXT NOT NULL,
    status VARCHAR(20) NOT NULL,
    error TEXT NULL,
    attempts INTEGER NOT NULL DEFAULT 1,
    received_at TIMESTAMP NOT NULL,
    processed_at TIMESTAMP NULL,
    UNIQUE (provider, event_id)
);

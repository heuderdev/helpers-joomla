-- WebhookHelper: eventos recebidos (MySQL / MariaDB).
-- O helper cria esta tabela sozinho no primeiro uso; use este arquivo se o
-- usuário do banco do site não tiver permissão de CREATE.
CREATE TABLE IF NOT EXISTS `#__helpers_webhooks` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `provider` VARCHAR(50) NOT NULL,
    `event_id` VARCHAR(191) NOT NULL,
    `event_type` VARCHAR(100) NOT NULL DEFAULT '',
    `payload` MEDIUMTEXT NOT NULL,
    `status` VARCHAR(20) NOT NULL,
    `error` TEXT NULL,
    `attempts` INT UNSIGNED NOT NULL DEFAULT 1,
    `received_at` DATETIME NOT NULL,
    `processed_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_provider_event` (`provider`, `event_id`),
    KEY `idx_status_received` (`status`, `received_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

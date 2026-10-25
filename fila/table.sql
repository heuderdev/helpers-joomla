CREATE TABLE IF NOT EXISTS `#__queue_jobs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    `uuid` CHAR(36) NOT NULL,

    `queue` VARCHAR(100) NOT NULL DEFAULT 'default',

    `tipo` VARCHAR(150) NOT NULL,

    `payload` LONGTEXT NULL,

    `status` VARCHAR(30) NOT NULL DEFAULT 'pending',

    `prioridade` INT NOT NULL DEFAULT 0,

    `progresso` BIGINT UNSIGNED NOT NULL DEFAULT 0,

    `total` BIGINT UNSIGNED NOT NULL DEFAULT 0,

    `percentual` DECIMAL(7,4) NOT NULL DEFAULT 0.0000,

    `tentativas` INT UNSIGNED NOT NULL DEFAULT 0,

    `max_tentativas` INT UNSIGNED NOT NULL DEFAULT 3,

    `timeout_seconds` INT UNSIGNED NOT NULL DEFAULT 240,

    `retry_after_seconds` INT UNSIGNED NOT NULL DEFAULT 300,

    `disponivel_em` DATETIME NOT NULL,

    `iniciado_em` DATETIME NULL DEFAULT NULL,

    `finalizado_em` DATETIME NULL DEFAULT NULL,

    `lock_token` VARCHAR(100) NULL DEFAULT NULL,

    `lock_em` DATETIME NULL DEFAULT NULL,

    `last_heartbeat_at` DATETIME NULL DEFAULT NULL,

    `started_by` VARCHAR(150) NULL DEFAULT NULL,

    `usuario_id` INT UNSIGNED NULL DEFAULT NULL,

    `resultado` LONGTEXT NULL,

    `erro` LONGTEXT NULL,

    `criado_em` DATETIME NOT NULL,

    `atualizado_em` DATETIME NOT NULL,

    PRIMARY KEY (`id`),

    UNIQUE KEY `idx_queue_jobs_uuid` (`uuid`),

    KEY `idx_queue_jobs_dispatch` (
        `queue`,
        `status`,
        `disponivel_em`,
        `prioridade`,
        `id`
    ),

    KEY `idx_queue_jobs_status` (
        `status`,
        `disponivel_em`,
        `prioridade`,
        `id`
    ),

    KEY `idx_queue_jobs_tipo` (
        `tipo`,
        `status`,
        `disponivel_em`
    ),

    KEY `idx_queue_jobs_lock` (
        `status`,
        `lock_em`
    ),

    KEY `idx_queue_jobs_heartbeat` (
        `status`,
        `last_heartbeat_at`
    ),

    KEY `idx_queue_jobs_usuario` (
        `usuario_id`,
        `criado_em`
    ),

    KEY `idx_queue_jobs_criado` (
        `criado_em`
    ),

    KEY `idx_queue_jobs_finalizado` (
        `finalizado_em`
    )

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  ROW_FORMAT=DYNAMIC;
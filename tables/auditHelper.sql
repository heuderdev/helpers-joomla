CREATE TABLE IF NOT EXISTS `#__audit_logs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` CHAR(36) NOT NULL,
    `request_id` CHAR(36) NOT NULL,
    `event` VARCHAR(120) NOT NULL,
    `category` VARCHAR(100) NOT NULL DEFAULT 'sistema',
    `level` VARCHAR(20) NOT NULL DEFAULT 'info',
    `status` VARCHAR(30) NOT NULL DEFAULT 'success',

    `entity_type` VARCHAR(120) NULL DEFAULT NULL,
    `entity_id` VARCHAR(120) NULL DEFAULT NULL,
    `parent_entity_type` VARCHAR(120) NULL DEFAULT NULL,
    `parent_entity_id` VARCHAR(120) NULL DEFAULT NULL,

    `user_id` INT UNSIGNED NULL DEFAULT NULL,
    `user_name` VARCHAR(255) NULL DEFAULT NULL,
    `user_username` VARCHAR(255) NULL DEFAULT NULL,

    `ip_address` VARCHAR(64) NULL DEFAULT NULL,
    `user_agent` VARCHAR(1000) NULL DEFAULT NULL,
    `request_method` VARCHAR(10) NULL DEFAULT NULL,
    `request_uri` VARCHAR(2000) NULL DEFAULT NULL,
    `referer` VARCHAR(2000) NULL DEFAULT NULL,

    `description` TEXT NULL DEFAULT NULL,
    `before_data` LONGTEXT NULL DEFAULT NULL,
    `after_data` LONGTEXT NULL DEFAULT NULL,
    `metadata` LONGTEXT NULL DEFAULT NULL,

    `created_at` DATETIME NOT NULL,

    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_audit_logs_uuid` (`uuid`),
    KEY `idx_audit_logs_request_id` (`request_id`),
    KEY `idx_audit_logs_event` (`event`),
    KEY `idx_audit_logs_category` (`category`),
    KEY `idx_audit_logs_entity` (`entity_type`, `entity_id`),
    KEY `idx_audit_logs_parent_entity` (`parent_entity_type`, `parent_entity_id`),
    KEY `idx_audit_logs_user` (`user_id`),
    KEY `idx_audit_logs_status` (`status`),
    KEY `idx_audit_logs_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
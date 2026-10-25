-- RateLimitHelper: contadores de tentativas (MySQL / MariaDB).
-- O helper cria esta tabela sozinho no primeiro uso; use este arquivo se o
-- usuário do banco do site não tiver permissão de CREATE.
CREATE TABLE IF NOT EXISTS `#__helpers_rate_limits` (
    `chave` CHAR(40) NOT NULL,
    `hits` INT UNSIGNED NOT NULL DEFAULT 0,
    `expires_at` BIGINT NOT NULL,
    PRIMARY KEY (`chave`),
    KEY `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

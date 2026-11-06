-- ============================================================
-- pharma_ops : AuraGharTak Android app customers
-- Run on local pharma_ops and GMP gmpserver_advancedMaindb
-- ============================================================
USE `pharma_ops`;

CREATE TABLE IF NOT EXISTS `auraghatak_users` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `full_name`     VARCHAR(150) NOT NULL,
    `email`         VARCHAR(150) NULL,
    `phone`         VARCHAR(20) NOT NULL,
    `password`      VARCHAR(128) NOT NULL COMMENT 'Stored plain per app requirement',
    `address`       VARCHAR(500) NULL,
    `pincode`       VARCHAR(12) NULL,
    `city`          VARCHAR(100) NULL,
    `state`         VARCHAR(100) NULL,
    `date_of_birth` DATE NULL,
    `gender`        VARCHAR(20) NULL,
    `photo_file`    VARCHAR(255) NULL,
    `access_token`  CHAR(64) NULL,
    `status`        VARCHAR(20) NOT NULL DEFAULT 'active',
    `created_at`    DATETIME NOT NULL,
    `updated_at`    DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_auraghatak_phone` (`phone`),
    UNIQUE KEY `uq_auraghatak_token` (`access_token`),
    KEY `idx_auraghatak_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

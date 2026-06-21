-- ============================================================
-- pharma_ho : Head-Office / tenant control database
-- Clients (subscriptions), branches, users, roles, security.
-- ============================================================
CREATE DATABASE IF NOT EXISTS `pharma_ho`
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `pharma_ho`;

SET FOREIGN_KEY_CHECKS = 0;

-- ---------- Clients (tenants) ----------
CREATE TABLE IF NOT EXISTS `clients` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `business_name`       VARCHAR(150) NOT NULL,
    `owner_name`          VARCHAR(120) NOT NULL,
    `email`               VARCHAR(150) NOT NULL,
    `phone`               VARCHAR(20)  NOT NULL,
    `drug_license_no`     VARCHAR(60)  NOT NULL,
    `gstin`               VARCHAR(20)  NULL,
    `address`             VARCHAR(255) NULL,
    `city`                VARCHAR(80)  NULL,
    `state`               VARCHAR(80)  NULL,
    `pincode`             VARCHAR(12)  NULL,
    `subscription_plan`   VARCHAR(40)  NOT NULL DEFAULT 'trial',
    `subscription_status` ENUM('trial','active','past_due','suspended','cancelled') NOT NULL DEFAULT 'trial',
    `trial_ends_at`       DATETIME NULL,
    `subscription_ends_at` DATETIME NULL,
    `created_at`          DATETIME NOT NULL,
    `updated_at`          DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_clients_email` (`email`),
    UNIQUE KEY `uq_clients_dl` (`drug_license_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Branches / Units ----------
CREATE TABLE IF NOT EXISTS `branches` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id`       BIGINT UNSIGNED NOT NULL,
    `code`            VARCHAR(20)  NOT NULL,
    `name`            VARCHAR(150) NOT NULL,
    `is_head_office`  TINYINT(1)   NOT NULL DEFAULT 0,
    `drug_license_no` VARCHAR(60)  NULL,
    `gstin`           VARCHAR(20)  NULL,
    `address`         VARCHAR(255) NULL,
    `city`            VARCHAR(80)  NULL,
    `state`          VARCHAR(80)  NULL,
    `pincode`         VARCHAR(12)  NULL,
    `phone`           VARCHAR(20)  NULL,
    `status`          ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at`      DATETIME NOT NULL,
    `updated_at`      DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_branch_code` (`client_id`,`code`),
    KEY `idx_branch_client` (`client_id`),
    CONSTRAINT `fk_branch_client` FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Roles ----------
CREATE TABLE IF NOT EXISTS `roles` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`        VARCHAR(40)  NOT NULL,
    `label`       VARCHAR(80)  NOT NULL,
    `permissions` JSON NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_role_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Users / Employees (login) ----------
CREATE TABLE IF NOT EXISTS `users` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id`     BIGINT UNSIGNED NULL,
    `branch_id`     BIGINT UNSIGNED NULL,
    `name`          VARCHAR(120) NOT NULL,
    `email`         VARCHAR(150) NOT NULL,
    `phone`         VARCHAR(20)  NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `role`          VARCHAR(40)  NOT NULL DEFAULT 'cashier',
    `status`        ENUM('active','inactive','suspended') NOT NULL DEFAULT 'active',
    `last_login_at` DATETIME NULL,
    `created_at`    DATETIME NOT NULL,
    `updated_at`    DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_email` (`email`),
    KEY `idx_users_client` (`client_id`),
    KEY `idx_users_branch` (`branch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Refresh tokens (JWT rotation) ----------
CREATE TABLE IF NOT EXISTS `refresh_tokens` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    BIGINT UNSIGNED NOT NULL,
    `jti`        CHAR(32) NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `revoked_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_refresh_jti` (`jti`),
    KEY `idx_refresh_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Login attempts (throttling) ----------
CREATE TABLE IF NOT EXISTS `login_attempts` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `attempt_key` CHAR(40) NOT NULL,
    `email`       VARCHAR(150) NULL,
    `successful`  TINYINT(1) NOT NULL DEFAULT 0,
    `ip_address`  VARCHAR(45) NULL,
    `created_at`  DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_attempt_key` (`attempt_key`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Audit logs ----------
CREATE TABLE IF NOT EXISTS `audit_logs` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id`  BIGINT UNSIGNED NULL,
    `branch_id`  BIGINT UNSIGNED NULL,
    `user_id`    BIGINT UNSIGNED NULL,
    `action`     VARCHAR(80) NOT NULL,
    `entity`     VARCHAR(80) NULL,
    `entity_id`  VARCHAR(64) NULL,
    `meta`       JSON NULL,
    `ip_address` VARCHAR(45) NULL,
    `created_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_audit_client` (`client_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Subscriptions ledger ----------
CREATE TABLE IF NOT EXISTS `subscriptions` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id`  BIGINT UNSIGNED NOT NULL,
    `plan`       VARCHAR(40) NOT NULL,
    `status`     VARCHAR(20) NOT NULL,
    `amount`     DECIMAL(12,2) NOT NULL DEFAULT 0,
    `started_at` DATETIME NULL,
    `ends_at`    DATETIME NULL,
    `created_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_sub_client` (`client_id`),
    CONSTRAINT `fk_sub_client` FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------- Seed roles ----------
INSERT INTO `roles` (`name`,`label`,`permissions`) VALUES
    ('ho_admin','HO Administrator', JSON_ARRAY('*')),
    ('branch_admin','Branch Administrator', JSON_ARRAY('dashboard','masters','purchase','sales','reports','accounts')),
    ('pharmacist','Pharmacist', JSON_ARRAY('dashboard','sales','purchase','masters')),
    ('cashier','Cashier', JSON_ARRAY('dashboard','sales'))
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`);

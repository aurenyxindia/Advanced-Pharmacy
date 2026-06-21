-- ============================================================
-- pharma_ops : Accounts master (chart of accounts / ledgers)
-- Phase 2 - tenant-keyed by client_id.
-- ============================================================
USE `pharma_ops`;

CREATE TABLE IF NOT EXISTS `accounts` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id`       BIGINT UNSIGNED NOT NULL,
    `name`            VARCHAR(150) NOT NULL,
    `type`            ENUM('asset','liability','income','expense','equity','customer','supplier','bank','cash') NOT NULL DEFAULT 'expense',
    `opening_balance` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `balance_type`    ENUM('debit','credit') NOT NULL DEFAULT 'debit',
    `gstin`           VARCHAR(20) NULL,
    `is_active`       TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`      DATETIME NOT NULL,
    `updated_at`      DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_account_client` (`client_id`),
    KEY `idx_account_type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

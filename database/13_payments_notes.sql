-- ============================================================
-- Payments (receipt/payment vouchers) + Credit/Debit notes
-- ============================================================
USE `pharma_ops`;

CREATE TABLE IF NOT EXISTS `payment_vouchers` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id`    BIGINT UNSIGNED NOT NULL,
    `branch_id`    BIGINT UNSIGNED NOT NULL,
    `voucher_no`   VARCHAR(40) NULL,
    `voucher_type` ENUM('receipt','payment') NOT NULL DEFAULT 'receipt',
    `party_type`   VARCHAR(20) NULL,          -- customer / supplier / other
    `party_name`   VARCHAR(150) NOT NULL,
    `amount`       DECIMAL(12,2) NOT NULL DEFAULT 0,
    `mode`         VARCHAR(20) NOT NULL DEFAULT 'cash',
    `ref_no`       VARCHAR(60) NULL,
    `voucher_date` DATE NOT NULL,
    `narration`    VARCHAR(255) NULL,
    `created_by`   BIGINT UNSIGNED NULL,
    `created_at`   DATETIME NOT NULL,
    `updated_at`   DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_pv_tenant` (`client_id`, `branch_id`, `voucher_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `credit_notes` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id`       BIGINT UNSIGNED NOT NULL,
    `branch_id`       BIGINT UNSIGNED NOT NULL,
    `note_no`         VARCHAR(40) NULL,
    `party_name`      VARCHAR(150) NOT NULL,
    `against_invoice` VARCHAR(60) NULL,
    `amount`          DECIMAL(12,2) NOT NULL DEFAULT 0,
    `gst_amount`      DECIMAL(12,2) NOT NULL DEFAULT 0,
    `reason`          VARCHAR(255) NULL,
    `note_date`       DATE NOT NULL,
    `created_by`      BIGINT UNSIGNED NULL,
    `created_at`      DATETIME NOT NULL,
    `updated_at`      DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_cn_tenant` (`client_id`, `branch_id`, `note_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `debit_notes` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id`       BIGINT UNSIGNED NOT NULL,
    `branch_id`       BIGINT UNSIGNED NOT NULL,
    `note_no`         VARCHAR(40) NULL,
    `party_name`      VARCHAR(150) NOT NULL,
    `against_invoice` VARCHAR(60) NULL,
    `amount`          DECIMAL(12,2) NOT NULL DEFAULT 0,
    `gst_amount`      DECIMAL(12,2) NOT NULL DEFAULT 0,
    `reason`          VARCHAR(255) NULL,
    `note_date`       DATE NOT NULL,
    `created_by`      BIGINT UNSIGNED NULL,
    `created_at`      DATETIME NOT NULL,
    `updated_at`      DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_dn_tenant` (`client_id`, `branch_id`, `note_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

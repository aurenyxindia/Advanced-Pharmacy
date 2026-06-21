-- ============================================================
-- Stock ledger: every stock movement (IN/OUT) for stock book,
-- closing balance as-of any date, and the FDA tally report.
-- ============================================================
USE `pharma_ops`;

CREATE TABLE IF NOT EXISTS `stock_ledger` (
    `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id`          BIGINT UNSIGNED NOT NULL,
    `branch_id`          BIGINT UNSIGNED NOT NULL,
    `product_binding_id` BIGINT UNSIGNED NOT NULL,
    `batch_id`           BIGINT UNSIGNED NULL,
    `txn_date`           DATE NOT NULL,
    `txn_type`           ENUM('opening','purchase','sale','sale_return','purchase_return','transfer_in','transfer_out','expiry','adjustment') NOT NULL,
    `qty_in`             INT NOT NULL DEFAULT 0,
    `qty_out`            INT NOT NULL DEFAULT 0,
    `ref_type`           VARCHAR(20) NULL,
    `ref_id`             BIGINT UNSIGNED NULL,
    `note`               VARCHAR(255) NULL,
    `created_at`         DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_ledger_scope` (`client_id`, `branch_id`, `product_binding_id`, `txn_date`),
    KEY `idx_ledger_date` (`txn_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

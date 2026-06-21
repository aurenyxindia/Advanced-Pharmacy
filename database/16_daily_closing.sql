-- ============================================================
-- Migration 16: Daily Closing (day-end cash reconciliation)
-- ============================================================
USE `pharma_ops`;

CREATE TABLE IF NOT EXISTS `daily_closings` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id`        BIGINT UNSIGNED NOT NULL,
    `branch_id`        BIGINT UNSIGNED NOT NULL,
    `closing_date`     DATE NOT NULL,
    `sales_cash`       DECIMAL(12,2) NOT NULL DEFAULT 0,
    `sales_upi`        DECIMAL(12,2) NOT NULL DEFAULT 0,
    `sales_card`       DECIMAL(12,2) NOT NULL DEFAULT 0,
    `sales_credit`     DECIMAL(12,2) NOT NULL DEFAULT 0,
    `total_sales`      DECIMAL(12,2) NOT NULL DEFAULT 0,
    `customer_payment` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `debit_payment`    DECIMAL(12,2) NOT NULL DEFAULT 0,
    `today_customers`  INT NOT NULL DEFAULT 0,
    `expected_cash`    DECIMAL(12,2) NOT NULL DEFAULT 0,
    `available_cash`   DECIMAL(12,2) NOT NULL DEFAULT 0,
    `difference`       DECIMAL(12,2) NOT NULL DEFAULT 0,
    `closed_by`        BIGINT UNSIGNED NULL,
    `created_at`       DATETIME NOT NULL,
    `updated_at`       DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_closing` (`client_id`, `branch_id`, `closing_date`),
    KEY `idx_closing_date` (`closing_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

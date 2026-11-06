-- ============================================================
-- Stock Adjustment audit log: who adjusted what, when, from which IP, and why.
-- One row per adjusted batch line. Run against the OPS database
-- (pharma_ops / gmpserver_advancedMaindb).
-- StockAdjustmentService::ensureSchema() creates the same table on first use.
-- ============================================================

CREATE TABLE IF NOT EXISTS `stock_adjustment_audit_log` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id`           BIGINT UNSIGNED NOT NULL,
    `branch_id`           BIGINT UNSIGNED NOT NULL,
    `adjustment_id`       BIGINT UNSIGNED NOT NULL,
    `adjustment_no`       VARCHAR(60)     NOT NULL,
    `adjustment_type`     VARCHAR(30)     NOT NULL,
    `product_binding_id`  BIGINT UNSIGNED NOT NULL,
    `batch_id`            BIGINT UNSIGNED NOT NULL,
    `product_name`        VARCHAR(255)    NOT NULL,
    `batch_no`            VARCHAR(60)     NOT NULL,
    `system_unit_qty`     INT             NOT NULL DEFAULT 0,
    `physical_unit_qty`   INT             NOT NULL DEFAULT 0,
    `difference_unit_qty` INT             NOT NULL DEFAULT 0,
    `reason`              VARCHAR(255)    NOT NULL,
    `user_id`             BIGINT UNSIGNED NULL,
    `user_name`           VARCHAR(150)    NOT NULL,
    `user_role`           VARCHAR(40)     NULL,
    `ip_address`          VARCHAR(64)     NOT NULL,
    `user_agent`          VARCHAR(255)    NULL,
    `created_at`          DATETIME        NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_saal_scope` (`client_id`, `branch_id`, `created_at`),
    KEY `idx_saal_adjustment` (`adjustment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

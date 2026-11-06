-- ============================================================
-- Purchase Edit audit log: who edited which purchase, when, from which IP,
-- whether the stock was already sold at that time, and stock before / after.
-- One row per purchase line (product + batch) per edit. Run against the OPS database
-- (pharma_ops / gmpserver_advancedMaindb).
-- PurchaseService::ensureEditAuditSchema() creates the same table on first use.
-- Quantities: *_qty / *_free = packs as entered; *_units / stock_* / sold_units = base units.
-- ============================================================

CREATE TABLE IF NOT EXISTS `purchase_edit_audit_log` (
    `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id`          BIGINT UNSIGNED NOT NULL,
    `branch_id`          BIGINT UNSIGNED NOT NULL,
    `purchase_id`        BIGINT UNSIGNED NOT NULL,
    `po_no`              VARCHAR(60)     NULL,
    `invoice_no`         VARCHAR(60)     NULL,
    `supplier_name`      VARCHAR(255)    NULL,
    `change_type`        VARCHAR(20)     NOT NULL,
    `product_binding_id` BIGINT UNSIGNED NULL,
    `batch_id`           BIGINT UNSIGNED NULL,
    `product_name`       VARCHAR(255)    NOT NULL,
    `batch_no`           VARCHAR(60)     NULL,
    `old_pack`           VARCHAR(60)     NULL,
    `new_pack`           VARCHAR(60)     NULL,
    `old_qty`            DECIMAL(12,2)   NOT NULL DEFAULT 0,
    `new_qty`            DECIMAL(12,2)   NOT NULL DEFAULT 0,
    `old_free`           DECIMAL(12,2)   NOT NULL DEFAULT 0,
    `new_free`           DECIMAL(12,2)   NOT NULL DEFAULT 0,
    `old_units`          INT             NOT NULL DEFAULT 0,
    `new_units`          INT             NOT NULL DEFAULT 0,
    `old_rate`           DECIMAL(12,2)   NOT NULL DEFAULT 0,
    `new_rate`           DECIMAL(12,2)   NOT NULL DEFAULT 0,
    `old_mrp`            DECIMAL(12,2)   NOT NULL DEFAULT 0,
    `new_mrp`            DECIMAL(12,2)   NOT NULL DEFAULT 0,
    `was_sold`           TINYINT(1)      NOT NULL DEFAULT 0,
    `sold_units`         INT             NOT NULL DEFAULT 0,
    `stock_before`       INT             NOT NULL DEFAULT 0,
    `stock_after`        INT             NOT NULL DEFAULT 0,
    `bypass_used`        TINYINT(1)      NOT NULL DEFAULT 0,
    `user_id`            BIGINT UNSIGNED NULL,
    `user_name`          VARCHAR(150)    NOT NULL,
    `user_role`          VARCHAR(40)     NULL,
    `ip_address`         VARCHAR(64)     NOT NULL,
    `user_agent`         VARCHAR(255)    NULL,
    `created_at`         DATETIME        NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_peal_scope` (`client_id`, `branch_id`, `created_at`),
    KEY `idx_peal_purchase` (`purchase_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

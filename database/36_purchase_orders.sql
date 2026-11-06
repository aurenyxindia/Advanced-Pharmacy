-- ============================================================
-- Purchase Orders (Retail /purchaseOrder parity)
--   Customise PO  -> POC-{yyyy}-{seq}
--   Auto PO (Short Book) -> POA-{yyyy}-{seq}
-- Run against the OPS database (pharma_ops / gmpserver_advancedMaindb).
-- PurchaseOrderService::ensureSchema() creates the same tables on first use.
-- ============================================================

CREATE TABLE IF NOT EXISTS `purchase_orders` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id`        BIGINT UNSIGNED NOT NULL,
    `branch_id`        BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `po_no`            VARCHAR(40)     NOT NULL,
    `po_seq`           INT UNSIGNED    NOT NULL,
    `po_type`          VARCHAR(20)     NOT NULL DEFAULT 'Customise',
    `distributor_id`   BIGINT UNSIGNED NULL,
    `distributor_name` VARCHAR(150)    NOT NULL DEFAULT '',
    `total_items`      INT             NOT NULL DEFAULT 0,
    `order_date`       DATE            NOT NULL,
    `status`           VARCHAR(20)     NOT NULL DEFAULT 'Active',
    `created_by`       BIGINT UNSIGNED NULL,
    `created_at`       DATETIME        NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_po_client_no` (`client_id`, `po_no`),
    KEY `idx_po_tenant` (`client_id`, `branch_id`, `order_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `purchase_order_items` (
    `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `purchase_order_id`  BIGINT UNSIGNED NOT NULL,
    `product_binding_id` BIGINT UNSIGNED NULL,
    `master_product_id`  BIGINT UNSIGNED NULL,
    `product_name`       VARCHAR(200)    NOT NULL,
    `pack_size`          VARCHAR(40)     NULL,
    `product_category`   VARCHAR(80)     NULL,
    `dosage_form`        VARCHAR(60)     NULL,
    `mrp`                DECIMAL(12,2)   NOT NULL DEFAULT 0,
    `hsn_code`           VARCHAR(20)     NULL,
    `manufacturer`       VARCHAR(150)    NULL,
    `generic_name`       VARCHAR(255)    NULL,
    `qty`                DECIMAL(12,2)   NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_poi_order` (`purchase_order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

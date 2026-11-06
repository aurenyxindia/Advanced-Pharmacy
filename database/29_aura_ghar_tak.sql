-- ============================================================
-- pharma_ops : AuraGharTak (token shop + home delivery orders)
-- Run on local pharma_ops before using /app/aura-ghar-tak
-- ============================================================
USE `pharma_ops`;

CREATE TABLE IF NOT EXISTS `agt_share_links` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id`        BIGINT UNSIGNED NOT NULL,
    `branch_id`        BIGINT UNSIGNED NOT NULL,
    `token`            CHAR(32) NOT NULL,
    `patient_no`       VARCHAR(64) NULL,
    `patient_name`     VARCHAR(255) NOT NULL,
    `patient_contact`  VARCHAR(20) NOT NULL,
    `patient_address`  VARCHAR(500) NULL,
    `status`           VARCHAR(20) NOT NULL DEFAULT 'Active',
    `created_at`       DATETIME NOT NULL,
    `expires_at`       DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_agt_token` (`token`),
    KEY `idx_agt_share_client` (`client_id`, `branch_id`),
    KEY `idx_agt_share_contact` (`client_id`, `patient_contact`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `agt_orders` (
    `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id`             BIGINT UNSIGNED NOT NULL,
    `branch_id`             BIGINT UNSIGNED NOT NULL,
    `order_no`              VARCHAR(64) NOT NULL,
    `share_token`           CHAR(32) NULL,
    `patient_no`            VARCHAR(64) NULL,
    `patient_name`          VARCHAR(255) NOT NULL,
    `patient_contact`       VARCHAR(20) NOT NULL,
    `patient_address`       VARCHAR(500) NULL,
    `patient_note`          VARCHAR(500) NULL,
    `prescription_file`     VARCHAR(255) NULL,
    `status`                VARCHAR(32) NOT NULL DEFAULT 'Placed',
    `item_count`            INT NOT NULL DEFAULT 0,
    `estimated_total`       DECIMAL(15,2) NOT NULL DEFAULT 0,
    `sale_invoice_no`       VARCHAR(64) NULL,
    `sale_id`               BIGINT UNSIGNED NULL,
    `sale_net`              DECIMAL(15,2) NULL,
    `entry_date`            DATETIME NOT NULL,
    `ready_at`              DATETIME NULL,
    `out_for_delivery_at`   DATETIME NULL,
    `delivered_at`          DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_agt_order_no` (`client_id`, `order_no`),
    KEY `idx_agt_order_status` (`client_id`, `branch_id`, `status`),
    KEY `idx_agt_order_patient` (`client_id`, `patient_no`),
    KEY `idx_agt_order_contact` (`client_id`, `patient_contact`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `agt_order_items` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id`           BIGINT UNSIGNED NOT NULL,
    `order_id`            BIGINT UNSIGNED NOT NULL,
    `order_no`            VARCHAR(64) NOT NULL,
    `product_binding_id`  BIGINT UNSIGNED NULL,
    `product_name`        VARCHAR(255) NOT NULL,
    `batch_no`            VARCHAR(64) NULL,
    `pack_size`           VARCHAR(64) NULL,
    `category`            VARCHAR(128) NULL,
    `mrp`                 DECIMAL(15,2) NOT NULL DEFAULT 0,
    `unit_mrp`            DECIMAL(15,2) NOT NULL DEFAULT 0,
    `qty`                 DECIMAL(15,2) NOT NULL DEFAULT 0,
    `line_total`          DECIMAL(15,2) NOT NULL DEFAULT 0,
    `expiry_date`         VARCHAR(32) NULL,
    PRIMARY KEY (`id`),
    KEY `idx_agt_item_order` (`order_id`),
    KEY `idx_agt_item_client_order` (`client_id`, `order_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

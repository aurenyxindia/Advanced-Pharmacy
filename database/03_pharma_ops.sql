-- ============================================================
-- pharma_ops : Pharmacy operations (tenant-keyed by client_id + branch_id)
-- Product bindings, inventory (batch-wise), purchase, sales, customers.
-- Phase 1 establishes the schema; later phases extend the workflows.
-- ============================================================
CREATE DATABASE IF NOT EXISTS `pharma_ops`
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `pharma_ops`;

SET FOREIGN_KEY_CHECKS = 0;

-- ---------- Product bindings (master product bound to a tenant) ----------
CREATE TABLE IF NOT EXISTS `product_bindings` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id`         BIGINT UNSIGNED NOT NULL,
    `branch_id`         BIGINT UNSIGNED NOT NULL,
    `master_product_id` INT UNSIGNED NOT NULL,
    `product_name`      VARCHAR(200) NOT NULL,
    `generic_id`        INT UNSIGNED NULL,
    `schedule_category` ENUM('OTC','H','H1','X','G','RESTRICTED') NOT NULL DEFAULT 'OTC',
    `gst_rate`          DECIMAL(5,2) NOT NULL DEFAULT 12.00,
    `hsn_code`          VARCHAR(20) NULL,
    `reorder_level`     INT NOT NULL DEFAULT 0,
    `is_active`         TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`        DATETIME NOT NULL,
    `updated_at`        DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_binding` (`client_id`,`branch_id`,`master_product_id`),
    KEY `idx_binding_tenant` (`client_id`,`branch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Customers ----------
CREATE TABLE IF NOT EXISTS `customers` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id`  BIGINT UNSIGNED NOT NULL,
    `name`       VARCHAR(150) NOT NULL,
    `phone`      VARCHAR(20) NULL,
    `email`      VARCHAR(150) NULL,
    `address`    VARCHAR(255) NULL,
    `dob`        DATE NULL,
    `gstin`      VARCHAR(20) NULL,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_customer_client` (`client_id`),
    KEY `idx_customer_phone` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Suppliers ----------
CREATE TABLE IF NOT EXISTS `suppliers` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id`  BIGINT UNSIGNED NOT NULL,
    `name`       VARCHAR(150) NOT NULL,
    `phone`      VARCHAR(20) NULL,
    `email`      VARCHAR(150) NULL,
    `gstin`      VARCHAR(20) NULL,
    `address`    VARCHAR(255) NULL,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_supplier_client` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Doctors ----------
CREATE TABLE IF NOT EXISTS `doctors` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id`     BIGINT UNSIGNED NOT NULL,
    `name`          VARCHAR(150) NOT NULL,
    `registration_no` VARCHAR(60) NULL,
    `phone`         VARCHAR(20) NULL,
    `qualification` VARCHAR(120) NULL,
    `created_at`    DATETIME NOT NULL,
    `updated_at`    DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_doctor_client` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Inventory: batch-wise stock ----------
CREATE TABLE IF NOT EXISTS `inventory_batches` (
    `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id`          BIGINT UNSIGNED NOT NULL,
    `branch_id`          BIGINT UNSIGNED NOT NULL,
    `product_binding_id` BIGINT UNSIGNED NOT NULL,
    `batch_no`           VARCHAR(60) NOT NULL,
    `expiry_date`        DATE NULL,
    `mrp`                DECIMAL(12,2) NOT NULL DEFAULT 0,
    `purchase_rate`      DECIMAL(12,2) NOT NULL DEFAULT 0,
    `quantity`           INT NOT NULL DEFAULT 0,
    `created_at`         DATETIME NOT NULL,
    `updated_at`         DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_batch_tenant` (`client_id`,`branch_id`),
    KEY `idx_batch_expiry` (`expiry_date`),
    KEY `idx_batch_binding` (`product_binding_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Inventory: aggregate stock per product ----------
CREATE TABLE IF NOT EXISTS `inventory_stock` (
    `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id`          BIGINT UNSIGNED NOT NULL,
    `branch_id`          BIGINT UNSIGNED NOT NULL,
    `product_binding_id` BIGINT UNSIGNED NOT NULL,
    `quantity`           INT NOT NULL DEFAULT 0,
    `reorder_level`      INT NOT NULL DEFAULT 0,
    `updated_at`         DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_stock` (`client_id`,`branch_id`,`product_binding_id`),
    KEY `idx_stock_tenant` (`client_id`,`branch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Purchases ----------
CREATE TABLE IF NOT EXISTS `purchases` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id`       BIGINT UNSIGNED NOT NULL,
    `branch_id`       BIGINT UNSIGNED NOT NULL,
    `supplier_id`     BIGINT UNSIGNED NULL,
    `supplier_name`   VARCHAR(150) NULL,
    `invoice_no`      VARCHAR(60) NOT NULL,
    `invoice_date`    DATE NOT NULL,
    `gross_amount`    DECIMAL(12,2) NOT NULL DEFAULT 0,
    `scheme_amount`   DECIMAL(12,2) NOT NULL DEFAULT 0,
    `discount_amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `gst_amount`      DECIMAL(12,2) NOT NULL DEFAULT 0,
    `net_amount`      DECIMAL(12,2) NOT NULL DEFAULT 0,
    `created_by`      BIGINT UNSIGNED NULL,
    `created_at`      DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_purchase_tenant` (`client_id`,`branch_id`,`invoice_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `purchase_items` (
    `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `purchase_id`        BIGINT UNSIGNED NOT NULL,
    `product_binding_id` BIGINT UNSIGNED NULL,
    `product_name`       VARCHAR(200) NOT NULL,
    `batch_no`           VARCHAR(60) NULL,
    `expiry_date`        DATE NULL,
    `quantity`           INT NOT NULL DEFAULT 0,
    `free_quantity`      INT NOT NULL DEFAULT 0,
    `purchase_rate`      DECIMAL(12,2) NOT NULL DEFAULT 0,
    `mrp`                DECIMAL(12,2) NOT NULL DEFAULT 0,
    `discount_pct`       DECIMAL(5,2) NOT NULL DEFAULT 0,
    `gst_rate`           DECIMAL(5,2) NOT NULL DEFAULT 0,
    `net_rate`           DECIMAL(12,2) NOT NULL DEFAULT 0,
    `line_total`         DECIMAL(12,2) NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_pitem_purchase` (`purchase_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Sales ----------
CREATE TABLE IF NOT EXISTS `sales` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id`       BIGINT UNSIGNED NOT NULL,
    `branch_id`       BIGINT UNSIGNED NOT NULL,
    `invoice_no`      VARCHAR(60) NOT NULL,
    `invoice_date`    DATETIME NOT NULL,
    `customer_id`     BIGINT UNSIGNED NULL,
    `customer_name`   VARCHAR(150) NULL,
    `doctor_name`     VARCHAR(150) NULL,
    `gross_amount`    DECIMAL(12,2) NOT NULL DEFAULT 0,
    `discount_amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `gst_amount`      DECIMAL(12,2) NOT NULL DEFAULT 0,
    `net_amount`      DECIMAL(12,2) NOT NULL DEFAULT 0,
    `profit_amount`   DECIMAL(12,2) NOT NULL DEFAULT 0,
    `payment_mode`    VARCHAR(20) NOT NULL DEFAULT 'cash',
    `has_prescription` TINYINT(1) NOT NULL DEFAULT 0,
    `is_locked`       TINYINT(1) NOT NULL DEFAULT 0,
    `created_by`      BIGINT UNSIGNED NULL,
    `created_at`      DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_sales_tenant` (`client_id`,`branch_id`,`invoice_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sale_items` (
    `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `sale_id`            BIGINT UNSIGNED NOT NULL,
    `product_binding_id` BIGINT UNSIGNED NULL,
    `batch_id`           BIGINT UNSIGNED NULL,
    `product_name`       VARCHAR(200) NOT NULL,
    `batch_no`           VARCHAR(60) NULL,
    `schedule_category`  ENUM('OTC','H','H1','X','G','RESTRICTED') NOT NULL DEFAULT 'OTC',
    `quantity`           INT NOT NULL DEFAULT 0,
    `rate`               DECIMAL(12,2) NOT NULL DEFAULT 0,
    `discount_pct`       DECIMAL(5,2) NOT NULL DEFAULT 0,
    `gst_rate`           DECIMAL(5,2) NOT NULL DEFAULT 0,
    `cost_price`         DECIMAL(12,2) NOT NULL DEFAULT 0,
    `line_total`         DECIMAL(12,2) NOT NULL DEFAULT 0,
    `profit`             DECIMAL(12,2) NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_sitem_sale` (`sale_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Prescriptions (Schedule H1/X/G uploads) ----------
CREATE TABLE IF NOT EXISTS `prescriptions` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id`    BIGINT UNSIGNED NOT NULL,
    `branch_id`    BIGINT UNSIGNED NOT NULL,
    `sale_id`      BIGINT UNSIGNED NULL,
    `customer_id`  BIGINT UNSIGNED NULL,
    `doctor_name`  VARCHAR(150) NULL,
    `file_path`    VARCHAR(255) NULL,
    `source`       ENUM('upload','camera') NOT NULL DEFAULT 'upload',
    `ocr_text`     TEXT NULL,
    `created_at`   DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_presc_tenant` (`client_id`,`branch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

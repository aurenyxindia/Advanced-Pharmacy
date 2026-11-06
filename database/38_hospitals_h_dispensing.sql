-- ============================================================
-- Hospital Master + Hospital Dispensing (Purchase → Dispensing).
-- Run against the OPS database (pharma_ops / gmpserver_advancedMaindb).
-- DispensingService::ensureSchema() creates the same tables on first use.
-- ============================================================

-- Hospitals attached to the medical shop (Retail master/hospital: name, address, phone)
-- plus one default hospital per branch for the Dispensing form.
CREATE TABLE IF NOT EXISTS `hospitals` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id`   BIGINT UNSIGNED NOT NULL,
    `branch_id`   BIGINT UNSIGNED NOT NULL,
    `name`        VARCHAR(150)    NOT NULL,
    `address`     VARCHAR(255)    NULL,
    `phone`       VARCHAR(20)     NULL,
    `is_default`  TINYINT(1)      NOT NULL DEFAULT 0,
    `created_at`  DATETIME        NOT NULL,
    `updated_at`  DATETIME        NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_hospital_scope` (`client_id`, `branch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- All dispensing info in one table: one row per dispensed batch line,
-- grouped by dispensing_no. No payment — amount is kept for record (MRP based).
CREATE TABLE IF NOT EXISTS `h_dispensing` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id`           BIGINT UNSIGNED NOT NULL,
    `branch_id`           BIGINT UNSIGNED NOT NULL,
    `dispensing_no`       VARCHAR(40)     NOT NULL,
    `dispensed_at`        DATETIME        NOT NULL,
    `hospital_id`         BIGINT UNSIGNED NULL,
    `hospital_name`       VARCHAR(150)    NOT NULL,
    `patient_name`        VARCHAR(150)    NULL,
    `department`          VARCHAR(100)    NULL,
    `remarks`             VARCHAR(255)    NULL,
    `product_binding_id`  BIGINT UNSIGNED NOT NULL,
    `batch_id`            BIGINT UNSIGNED NOT NULL,
    `product_name`        VARCHAR(255)    NOT NULL,
    `pack_size`           VARCHAR(40)     NULL,
    `batch_no`            VARCHAR(60)     NOT NULL,
    `expiry_date`         DATE            NULL,
    `units_per_pack`      INT             NOT NULL DEFAULT 1,
    `qty`                 INT             NOT NULL,
    `unit_mrp`            DECIMAL(12,4)   NOT NULL DEFAULT 0,
    `pack_mrp`            DECIMAL(12,2)   NOT NULL DEFAULT 0,
    `discount_pct`        DECIMAL(6,2)    NOT NULL DEFAULT 0,
    `amount`              DECIMAL(12,2)   NOT NULL DEFAULT 0,
    `user_id`             BIGINT UNSIGNED NULL,
    `user_name`           VARCHAR(150)    NOT NULL,
    `created_at`          DATETIME        NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_hd_scope` (`client_id`, `branch_id`, `dispensed_at`),
    KEY `idx_hd_no` (`client_id`, `branch_id`, `dispensing_no`),
    KEY `idx_hd_hospital` (`client_id`, `hospital_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

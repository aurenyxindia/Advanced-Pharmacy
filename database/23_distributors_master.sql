-- ============================================================
-- Distributors master (global catalog DB: pharma_master)
-- Renamed from ops `suppliers` — tenant-scoped via client_id.
-- ============================================================
USE `pharma_master`;

CREATE TABLE IF NOT EXISTS `distributors` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id`         BIGINT UNSIGNED NOT NULL,
    `distributor_code`  VARCHAR(20) NULL,
    `name`              VARCHAR(150) NOT NULL,
    `phone`             VARCHAR(20) NULL,
    `contact_person`    VARCHAR(120) NULL,
    `email`             VARCHAR(150) NULL,
    `state`             VARCHAR(80) NULL,
    `gstin`             VARCHAR(20) NULL,
    `gst_state_code`    VARCHAR(20) NULL,
    `address`           VARCHAR(255) NULL,
    `dl_no1`            VARCHAR(60) NULL,
    `dl_no2`            VARCHAR(60) NULL,
    `dl_no3`            VARCHAR(60) NULL,
    `dl_no4`            VARCHAR(60) NULL,
    `gst_type`          VARCHAR(20) NULL DEFAULT 'GST',
    `credit_limit_days` INT NULL,
    `is_general`        TINYINT(1) NOT NULL DEFAULT 0,
    `is_active`         TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`        DATETIME NOT NULL,
    `updated_at`        DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_distributor_client` (`client_id`),
    KEY `idx_distributor_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migrate existing ops suppliers (local dev).
INSERT INTO `distributors` (
    `id`, `client_id`, `distributor_code`, `name`, `phone`, `contact_person`, `email`,
    `state`, `gstin`, `gst_state_code`, `address`,
    `dl_no1`, `dl_no2`, `dl_no3`, `dl_no4`, `gst_type`, `credit_limit_days`,
    `is_general`, `is_active`, `created_at`, `updated_at`
)
SELECT
    s.`id`, s.`client_id`, s.`distributor_code`, s.`name`, s.`phone`, s.`contact_person`, s.`email`,
    s.`state`, s.`gstin`, s.`gst_state_code`, s.`address`,
    s.`dl_no1`, s.`dl_no2`, s.`dl_no3`, s.`dl_no4`, s.`gst_type`, s.`credit_limit_days`,
    COALESCE(s.`is_general`, 0), COALESCE(s.`is_active`, 1), s.`created_at`, s.`updated_at`
FROM `pharma_ops`.`suppliers` s
WHERE NOT EXISTS (SELECT 1 FROM `distributors` d WHERE d.`id` = s.`id`);

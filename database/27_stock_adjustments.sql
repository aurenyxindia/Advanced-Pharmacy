-- Stock adjustment header + lines (Retail parity under Opening Stock).
USE `pharma_ops`;

CREATE TABLE IF NOT EXISTS `stock_adjustments` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id`        BIGINT UNSIGNED NOT NULL,
    `branch_id`        BIGINT UNSIGNED NOT NULL,
    `adjustment_no`    VARCHAR(60) NOT NULL,
    `adjustment_date`  DATE NOT NULL,
    `adjustment_type`  ENUM('PHYSICAL_COUNT','DAMAGE','EXPIRY','THEFT','OTHER') NOT NULL DEFAULT 'PHYSICAL_COUNT',
    `remarks`          VARCHAR(255) NULL,
    `total_items`      INT NOT NULL DEFAULT 0,
    `status`           VARCHAR(20) NOT NULL DEFAULT 'APPROVED',
    `entry_by`         VARCHAR(120) NULL,
    `created_at`       DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_adj_no` (`client_id`, `branch_id`, `adjustment_no`),
    KEY `idx_adj_scope` (`client_id`, `branch_id`, `adjustment_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `stock_adjustment_lines` (
    `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `adjustment_id`        BIGINT UNSIGNED NOT NULL,
    `client_id`            BIGINT UNSIGNED NOT NULL,
    `branch_id`            BIGINT UNSIGNED NOT NULL,
    `product_binding_id`   BIGINT UNSIGNED NOT NULL,
    `batch_id`             BIGINT UNSIGNED NOT NULL,
    `product_name`         VARCHAR(255) NOT NULL,
    `batch_no`             VARCHAR(60) NOT NULL,
    `system_qty`           INT NOT NULL DEFAULT 0 COMMENT 'system packs',
    `system_unit_qty`      INT NOT NULL DEFAULT 0 COMMENT 'system base units',
    `physical_qty`         INT NOT NULL DEFAULT 0 COMMENT 'physical packs',
    `physical_unit_qty`    INT NOT NULL DEFAULT 0 COMMENT 'physical base units',
    `difference_qty`       INT NOT NULL DEFAULT 0,
    `difference_unit_qty`  INT NOT NULL DEFAULT 0,
    `reason`               VARCHAR(255) NULL,
    `created_at`           DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_adj_lines_header` (`adjustment_id`),
    KEY `idx_adj_lines_batch` (`client_id`, `branch_id`, `batch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

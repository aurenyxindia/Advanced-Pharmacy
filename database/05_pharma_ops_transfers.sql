-- ============================================================
-- pharma_ops : Interbranch stock transfers (Phase 6)
-- Stock moves from one branch to another via a transfer document.
-- ============================================================
USE `pharma_ops`;

CREATE TABLE IF NOT EXISTS `stock_transfers` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id`      BIGINT UNSIGNED NOT NULL,
    `from_branch_id` BIGINT UNSIGNED NOT NULL,
    `to_branch_id`   BIGINT UNSIGNED NOT NULL,
    `transfer_no`    VARCHAR(60) NOT NULL,
    `transfer_date`  DATE NOT NULL,
    `total_value`    DECIMAL(14,2) NOT NULL DEFAULT 0,
    `notes`          VARCHAR(255) NULL,
    `created_by`     BIGINT UNSIGNED NULL,
    `created_at`     DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_transfer_client` (`client_id`),
    KEY `idx_transfer_branches` (`from_branch_id`, `to_branch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `stock_transfer_items` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `transfer_id`       BIGINT UNSIGNED NOT NULL,
    `master_product_id` INT UNSIGNED NOT NULL,
    `product_name`      VARCHAR(200) NOT NULL,
    `batch_no`          VARCHAR(60) NULL,
    `quantity`          INT NOT NULL DEFAULT 0,
    `cost_per_unit`     DECIMAL(12,4) NOT NULL DEFAULT 0,
    `line_value`        DECIMAL(14,2) NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_transfer_item` (`transfer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

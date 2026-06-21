-- ============================================================
-- pharma_ops : Online orders / ePharmacy + delivery tracking (Phase 8)
-- ============================================================
USE `pharma_ops`;

CREATE TABLE IF NOT EXISTS `online_orders` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id`      BIGINT UNSIGNED NOT NULL,
    `branch_id`      BIGINT UNSIGNED NOT NULL,
    `order_no`       VARCHAR(40) NOT NULL,
    `tracking_token` CHAR(32) NOT NULL,
    `customer_name`  VARCHAR(150) NOT NULL,
    `phone`          VARCHAR(20) NOT NULL,
    `email`          VARCHAR(150) NULL,
    `address`        VARCHAR(400) NOT NULL,
    `pincode`        VARCHAR(12) NULL,
    `total_estimate` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `status`         ENUM('placed','confirmed','packed','out_for_delivery','delivered','cancelled') NOT NULL DEFAULT 'placed',
    `assigned_to`    VARCHAR(120) NULL,
    `prescription_id` BIGINT UNSIGNED NULL,
    `notes`          VARCHAR(400) NULL,
    `sale_id`        BIGINT UNSIGNED NULL,
    `created_at`     DATETIME NOT NULL,
    `updated_at`     DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_order_no` (`order_no`),
    KEY `idx_order_tenant` (`client_id`, `branch_id`, `status`),
    KEY `idx_order_token` (`tracking_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `online_order_items` (
    `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `order_id`           BIGINT UNSIGNED NOT NULL,
    `product_binding_id` BIGINT UNSIGNED NULL,
    `product_name`       VARCHAR(200) NOT NULL,
    `quantity`           INT NOT NULL DEFAULT 1,
    `rate`               DECIMAL(12,2) NOT NULL DEFAULT 0,
    `line_total`         DECIMAL(12,2) NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_order_item` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Status history for delivery tracking timeline.
CREATE TABLE IF NOT EXISTS `online_order_events` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `order_id`   BIGINT UNSIGNED NOT NULL,
    `status`     VARCHAR(30) NOT NULL,
    `note`       VARCHAR(255) NULL,
    `created_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_event_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

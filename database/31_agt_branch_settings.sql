-- ============================================================
-- pharma_ops : AuraGharTak branch policy (shop-level, not per product)
-- Run on local pharma_ops; service also auto-creates via ensureSchema
-- ============================================================
USE `pharma_ops`;

CREATE TABLE IF NOT EXISTS `agt_branch_settings` (
    `client_id`              BIGINT UNSIGNED NOT NULL,
    `branch_id`              BIGINT UNSIGNED NOT NULL,
    `allow_customer_cancel`  TINYINT(1) NOT NULL DEFAULT 0,
    `updated_at`             DATETIME NOT NULL,
    PRIMARY KEY (`client_id`, `branch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

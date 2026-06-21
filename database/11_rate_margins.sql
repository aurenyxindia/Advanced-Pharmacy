-- ============================================================
-- Rate Margin Master (category-wise wholesale pricing)
--  * product category: Allopathy / Ayurvedic / Generic / Surgical / Cosmetic
--  * per-category PTR margin (retailer) and PTS margin (stockist/distributor)
--  * PTR/PTS auto-derived from MRP:
--      net_mrp = MRP / (1 + GST%)            (reverse GST)
--      PTR     = net_mrp / (1 + ptr_margin%) (excl GST; GST added forward when billing)
--      PTS     = net_mrp / (1 + pts_margin%)
-- ============================================================

USE `pharma_master`;
ALTER TABLE `products`
    ADD COLUMN IF NOT EXISTS `product_category` VARCHAR(30) NOT NULL DEFAULT 'Allopathy' AFTER `schedule_category`;

USE `pharma_ops`;
ALTER TABLE `product_bindings`
    ADD COLUMN IF NOT EXISTS `product_category` VARCHAR(30) NOT NULL DEFAULT 'Allopathy' AFTER `schedule_category`;

CREATE TABLE IF NOT EXISTS `rate_margins` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id`  BIGINT UNSIGNED NOT NULL,
    `category`   VARCHAR(30) NOT NULL,
    `ptr_margin` DECIMAL(5,2) NOT NULL DEFAULT 20.00,
    `pts_margin` DECIMAL(5,2) NOT NULL DEFAULT 30.00,
    `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_margin_cat` (`client_id`, `category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

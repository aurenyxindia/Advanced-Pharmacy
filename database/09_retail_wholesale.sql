-- ============================================================
-- Retail + Wholesale billing from a common purchase
--  * client business_type (retail / wholesale / both)
--  * PTR / PTS (price to retailer / stockist) on products, bindings, batches, purchase lines
--  * wholesale fields on sales (bill mode, rate type, CD discount, GST split)
-- ============================================================

-- ---------- Client business type ----------
USE `pharma_ho`;
ALTER TABLE `clients`
    ADD COLUMN IF NOT EXISTS `business_type` ENUM('retail','wholesale','both') NOT NULL DEFAULT 'both' AFTER `subscription_plan`;

-- ---------- Master products: default PTR/PTS ----------
USE `pharma_master`;
ALTER TABLE `products`
    ADD COLUMN IF NOT EXISTS `ptr` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `default_gst_rate`,
    ADD COLUMN IF NOT EXISTS `pts` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `ptr`;

-- ---------- Operations ----------
USE `pharma_ops`;

ALTER TABLE `product_bindings`
    ADD COLUMN IF NOT EXISTS `ptr` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `gst_rate`,
    ADD COLUMN IF NOT EXISTS `pts` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `ptr`;

-- PTR/PTS are stored per PACK on the batch (wholesale deals in packs).
ALTER TABLE `inventory_batches`
    ADD COLUMN IF NOT EXISTS `ptr` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `mrp`,
    ADD COLUMN IF NOT EXISTS `pts` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `ptr`;

ALTER TABLE `purchase_items`
    ADD COLUMN IF NOT EXISTS `hsn_code` VARCHAR(20) NULL AFTER `product_name`,
    ADD COLUMN IF NOT EXISTS `ptr` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `mrp`,
    ADD COLUMN IF NOT EXISTS `pts` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `ptr`,
    ADD COLUMN IF NOT EXISTS `scheme_pct` DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER `pts`;

ALTER TABLE `sales`
    ADD COLUMN IF NOT EXISTS `bill_mode` VARCHAR(12) NOT NULL DEFAULT 'retail' AFTER `sale_type`,
    ADD COLUMN IF NOT EXISTS `rate_type` VARCHAR(10) NULL AFTER `bill_mode`,
    ADD COLUMN IF NOT EXISTS `email` VARCHAR(150) NULL AFTER `contact_no`,
    ADD COLUMN IF NOT EXISTS `cd_disc_pct` DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER `discount_amount`,
    ADD COLUMN IF NOT EXISTS `cd_amount` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `cd_disc_pct`,
    ADD COLUMN IF NOT EXISTS `is_interstate` TINYINT(1) NOT NULL DEFAULT 0 AFTER `gst_amount`,
    ADD COLUMN IF NOT EXISTS `cgst` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `is_interstate`,
    ADD COLUMN IF NOT EXISTS `sgst` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `cgst`,
    ADD COLUMN IF NOT EXISTS `igst` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `sgst`;

ALTER TABLE `sale_items`
    ADD COLUMN IF NOT EXISTS `total_amt` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `line_total`,
    ADD COLUMN IF NOT EXISTS `cd_disc_amt` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `total_amt`;

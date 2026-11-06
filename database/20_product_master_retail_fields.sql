-- ============================================================
-- Product Master: Retail parity fields (keep Advanced extras)
-- product_type, dosage_form, packing_desc, old_mrp
-- Widen product_category for Retail drugClass labels
-- ============================================================
USE `pharma_master`;

ALTER TABLE `products`
    ADD COLUMN IF NOT EXISTS `product_type` VARCHAR(40) NOT NULL DEFAULT 'Medicine'
        AFTER `name`,
    ADD COLUMN IF NOT EXISTS `dosage_form` VARCHAR(60) NULL
        AFTER `pack_size`,
    ADD COLUMN IF NOT EXISTS `packing_desc` VARCHAR(120) NULL
        AFTER `dosage_form`,
    ADD COLUMN IF NOT EXISTS `old_mrp` DECIMAL(12,2) NULL DEFAULT NULL
        AFTER `mrp`;

ALTER TABLE `products`
    MODIFY COLUMN `product_category` VARCHAR(80) NOT NULL DEFAULT 'Allopathy';

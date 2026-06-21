-- ============================================================
-- Manufacturer short form (Mfg code) for bills
-- ============================================================
USE `pharma_master`;
ALTER TABLE `products`
    ADD COLUMN IF NOT EXISTS `manufacturer_short` VARCHAR(20) NULL AFTER `manufacturer`;

USE `pharma_ops`;
ALTER TABLE `product_bindings`
    ADD COLUMN IF NOT EXISTS `manufacturer` VARCHAR(150) NULL AFTER `product_name`,
    ADD COLUMN IF NOT EXISTS `manufacturer_short` VARCHAR(20) NULL AFTER `manufacturer`;

ALTER TABLE `sale_items`
    ADD COLUMN IF NOT EXISTS `manufacturer_short` VARCHAR(20) NULL AFTER `product_name`;

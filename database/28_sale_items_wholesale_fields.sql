-- Wholesale sale line fields (Retail wsales parity).
-- Run on pharma_ops after approval.

USE `pharma_ops`;

ALTER TABLE `sale_items`
    ADD COLUMN IF NOT EXISTS `free_quantity` INT NOT NULL DEFAULT 0 AFTER `quantity`,
    ADD COLUMN IF NOT EXISTS `oth_rate` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `rate`,
    ADD COLUMN IF NOT EXISTS `base_rate` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `oth_rate`,
    ADD COLUMN IF NOT EXISTS `net_rate` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `base_rate`,
    ADD COLUMN IF NOT EXISTS `billing_with` VARCHAR(20) NOT NULL DEFAULT 'Sale Rate' AFTER `net_rate`,
    ADD COLUMN IF NOT EXISTS `sale_scheme_pct` DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER `billing_with`;

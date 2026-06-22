-- ============================================================
-- Migration 17: MRP on the Product Master (required for retail billing)
-- ============================================================

ALTER TABLE `pharma_master`.`products`
    ADD COLUMN IF NOT EXISTS `mrp` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `default_gst_rate`;

ALTER TABLE `pharma_ops`.`product_bindings`
    ADD COLUMN IF NOT EXISTS `mrp` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `pts`;

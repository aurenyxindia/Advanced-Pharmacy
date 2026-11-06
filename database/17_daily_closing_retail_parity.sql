-- ============================================================
-- Migration 17: Daily Closing Retail parity (return + net sales)
-- Additive only — safe to run on live.
-- ============================================================
USE `pharma_ops`;

ALTER TABLE `daily_closings`
  ADD COLUMN IF NOT EXISTS `retail_return` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `total_sales`,
  ADD COLUMN IF NOT EXISTS `net_sales` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `retail_return`;

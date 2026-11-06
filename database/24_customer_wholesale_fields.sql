-- Wholesale customer fields (Retail patient master parity).
-- Local ops DB.
USE `pharma_ops`;

ALTER TABLE `customers`
    ADD COLUMN IF NOT EXISTS `dl_no` VARCHAR(60) NULL AFTER `gstin`,
    ADD COLUMN IF NOT EXISTS `medical_type` VARCHAR(20) NULL AFTER `dl_no`,
    ADD COLUMN IF NOT EXISTS `fssai_no` VARCHAR(14) NULL AFTER `medical_type`,
    ADD COLUMN IF NOT EXISTS `credit_days` INT NULL DEFAULT 0 AFTER `fssai_no`,
    ADD COLUMN IF NOT EXISTS `state` VARCHAR(80) NULL AFTER `credit_days`,
    ADD COLUMN IF NOT EXISTS `city` VARCHAR(80) NULL AFTER `state`,
    ADD COLUMN IF NOT EXISTS `area` VARCHAR(120) NULL AFTER `city`,
    ADD COLUMN IF NOT EXISTS `pp_client_no` VARCHAR(32) NULL AFTER `area`;

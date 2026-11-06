-- Masters parity: Customer, Doctor, Rate Margin, Employee, Accounts bank fields.
-- Live DBs.

USE `paperlesspharmac_advancedMaindb`;

ALTER TABLE `customers`
    ADD COLUMN IF NOT EXISTS `gender` VARCHAR(10) NULL AFTER `customer_type`,
    ADD COLUMN IF NOT EXISTS `gst_registered` TINYINT(1) NOT NULL DEFAULT 0 AFTER `gender`,
    ADD COLUMN IF NOT EXISTS `pan_no` VARCHAR(10) NULL AFTER `pp_client_no`,
    ADD COLUMN IF NOT EXISTS `chronic_val` VARCHAR(40) NULL AFTER `is_chronic`,
    ADD COLUMN IF NOT EXISTS `others_val` VARCHAR(100) NULL AFTER `chronic_val`;

ALTER TABLE `doctors`
    ADD COLUMN IF NOT EXISTS `clinic_hospital_name` VARCHAR(150) NULL AFTER `name`,
    ADD COLUMN IF NOT EXISTS `email` VARCHAR(150) NULL AFTER `phone`,
    ADD COLUMN IF NOT EXISTS `address` VARCHAR(255) NULL AFTER `email`;

ALTER TABLE `rate_margins`
    ADD COLUMN IF NOT EXISTS `max_discount` DECIMAL(5,2) NULL DEFAULT 0 AFTER `pts_margin`,
    ADD COLUMN IF NOT EXISTS `std_discount` DECIMAL(5,2) NULL DEFAULT 0 AFTER `max_discount`,
    ADD COLUMN IF NOT EXISTS `rate_by` VARCHAR(40) NULL DEFAULT 'Rate By Margin' AFTER `std_discount`,
    ADD COLUMN IF NOT EXISTS `credit_days` INT NULL DEFAULT 0 AFTER `rate_by`,
    ADD COLUMN IF NOT EXISTS `cash_discount` DECIMAL(5,2) NULL DEFAULT 0 AFTER `credit_days`;

ALTER TABLE `accounts`
    ADD COLUMN IF NOT EXISTS `bank_name` VARCHAR(150) NULL AFTER `gstin`,
    ADD COLUMN IF NOT EXISTS `account_no` VARCHAR(40) NULL AFTER `bank_name`,
    ADD COLUMN IF NOT EXISTS `account_holder_name` VARCHAR(150) NULL AFTER `account_no`,
    ADD COLUMN IF NOT EXISTS `ifsc_code` VARCHAR(11) NULL AFTER `account_holder_name`,
    ADD COLUMN IF NOT EXISTS `bank_branch` VARCHAR(120) NULL AFTER `ifsc_code`,
    ADD COLUMN IF NOT EXISTS `account_type` VARCHAR(20) NULL AFTER `bank_branch`;

USE `paperlesspharmac_advho`;

ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `reg_no` VARCHAR(40) NULL AFTER `phone`,
    ADD COLUMN IF NOT EXISTS `joining_date` DATE NULL AFTER `reg_no`,
    ADD COLUMN IF NOT EXISTS `address` VARCHAR(255) NULL AFTER `joining_date`;

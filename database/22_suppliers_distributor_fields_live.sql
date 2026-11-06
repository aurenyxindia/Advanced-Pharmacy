USE `paperlesspharmac_advancedMaindb`;

ALTER TABLE `suppliers`
    ADD COLUMN IF NOT EXISTS `distributor_code` VARCHAR(20) NULL AFTER `client_id`,
    ADD COLUMN IF NOT EXISTS `contact_person` VARCHAR(120) NULL AFTER `phone`,
    ADD COLUMN IF NOT EXISTS `state` VARCHAR(80) NULL AFTER `email`,
    ADD COLUMN IF NOT EXISTS `gst_state_code` VARCHAR(20) NULL AFTER `gstin`,
    ADD COLUMN IF NOT EXISTS `dl_no1` VARCHAR(60) NULL AFTER `address`,
    ADD COLUMN IF NOT EXISTS `dl_no2` VARCHAR(60) NULL AFTER `dl_no1`,
    ADD COLUMN IF NOT EXISTS `dl_no3` VARCHAR(60) NULL AFTER `dl_no2`,
    ADD COLUMN IF NOT EXISTS `dl_no4` VARCHAR(60) NULL AFTER `dl_no3`,
    ADD COLUMN IF NOT EXISTS `gst_type` VARCHAR(20) NULL DEFAULT 'GST' AFTER `dl_no4`,
    ADD COLUMN IF NOT EXISTS `credit_limit_days` INT NULL AFTER `gst_type`,
    ADD COLUMN IF NOT EXISTS `is_general` TINYINT(1) NOT NULL DEFAULT 0 AFTER `credit_limit_days`,
    ADD COLUMN IF NOT EXISTS `is_active` TINYINT(1) NOT NULL DEFAULT 1 AFTER `is_general`;

-- ============================================================
-- Advanced Sales & Purchase enhancements
--  * 'purchase' role
--  * unit / loose sale support (units_per_pack, shelf, allow_loose)
--  * multi-counter + patient details on sales
--  * pack/shelf/mrp/unit_type on sale lines
-- MariaDB 10.4 supports "ADD COLUMN IF NOT EXISTS".
-- ============================================================

-- ---------- New role ----------
USE `pharma_ho`;
INSERT INTO `roles` (`name`,`label`,`permissions`) VALUES
    ('purchase','Purchase Officer', JSON_ARRAY('dashboard','masters','purchase','reports'))
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`);

-- ---------- Master product: pack units, shelf, loose flag ----------
USE `pharma_master`;
ALTER TABLE `products`
    ADD COLUMN IF NOT EXISTS `units_per_pack` INT NOT NULL DEFAULT 1 AFTER `pack_size`,
    ADD COLUMN IF NOT EXISTS `allow_loose` TINYINT(1) NOT NULL DEFAULT 1 AFTER `units_per_pack`,
    ADD COLUMN IF NOT EXISTS `shelf_location` VARCHAR(40) NULL AFTER `allow_loose`;

-- ---------- Operations ----------
USE `pharma_ops`;

ALTER TABLE `product_bindings`
    ADD COLUMN IF NOT EXISTS `units_per_pack` INT NOT NULL DEFAULT 1 AFTER `gst_rate`,
    ADD COLUMN IF NOT EXISTS `allow_loose` TINYINT(1) NOT NULL DEFAULT 1 AFTER `units_per_pack`,
    ADD COLUMN IF NOT EXISTS `shelf_location` VARCHAR(40) NULL AFTER `allow_loose`,
    ADD COLUMN IF NOT EXISTS `pack_size` VARCHAR(40) NULL AFTER `shelf_location`;

-- inventory_batches.quantity now represents BASE (loose) units.
ALTER TABLE `inventory_batches`
    ADD COLUMN IF NOT EXISTS `shelf_location` VARCHAR(40) NULL AFTER `quantity`;

-- Sales header: counter, sale type, patient & contact details, reminder.
ALTER TABLE `sales`
    ADD COLUMN IF NOT EXISTS `counter` VARCHAR(30) NULL AFTER `branch_id`,
    ADD COLUMN IF NOT EXISTS `sale_type` VARCHAR(20) NOT NULL DEFAULT 'Regular' AFTER `counter`,
    ADD COLUMN IF NOT EXISTS `patient_name` VARCHAR(150) NULL AFTER `customer_name`,
    ADD COLUMN IF NOT EXISTS `contact_no` VARCHAR(20) NULL AFTER `patient_name`,
    ADD COLUMN IF NOT EXISTS `address` VARCHAR(255) NULL AFTER `contact_no`,
    ADD COLUMN IF NOT EXISTS `narration` VARCHAR(255) NULL AFTER `address`,
    ADD COLUMN IF NOT EXISTS `reminder_days` INT NULL AFTER `narration`,
    ADD COLUMN IF NOT EXISTS `taxable_amount` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `gross_amount`;

-- Sale lines: pack, shelf, mrp, unit type, taxable, expiry.
ALTER TABLE `sale_items`
    ADD COLUMN IF NOT EXISTS `pack` VARCHAR(40) NULL AFTER `product_name`,
    ADD COLUMN IF NOT EXISTS `shelf` VARCHAR(40) NULL AFTER `pack`,
    ADD COLUMN IF NOT EXISTS `mrp` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `rate`,
    ADD COLUMN IF NOT EXISTS `unit_type` VARCHAR(10) NOT NULL DEFAULT 'unit' AFTER `mrp`,
    ADD COLUMN IF NOT EXISTS `expiry_date` DATE NULL AFTER `batch_no`,
    ADD COLUMN IF NOT EXISTS `taxable` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `gst_rate`;

-- Purchase lines: shelf + units per pack.
ALTER TABLE `purchase_items`
    ADD COLUMN IF NOT EXISTS `shelf_location` VARCHAR(40) NULL AFTER `expiry_date`,
    ADD COLUMN IF NOT EXISTS `units_per_pack` INT NOT NULL DEFAULT 1 AFTER `quantity`;

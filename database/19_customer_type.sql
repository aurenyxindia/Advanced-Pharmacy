-- Customer type: retail / wholesale / ipd (aligned with old retail patient master).
USE `pharma_ops`;

ALTER TABLE `customers`
    ADD COLUMN IF NOT EXISTS `customer_type` ENUM('retail','wholesale','ipd') NOT NULL DEFAULT 'retail'
        AFTER `is_chronic`;

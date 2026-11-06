-- Sales Retail log/credit parity: Paid, Balance, Card Account.
-- Local: pharma_ops | Live: paperlesspharmac_advancedMaindb

USE `pharma_ops`;

ALTER TABLE `sales`
    ADD COLUMN IF NOT EXISTS `account_id` BIGINT UNSIGNED NULL AFTER `payment_mode`,
    ADD COLUMN IF NOT EXISTS `paid` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `account_id`,
    ADD COLUMN IF NOT EXISTS `balance` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `paid`;

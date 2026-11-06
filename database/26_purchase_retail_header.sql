-- Retail purchase header parity (Cash/Credit, Due, CD, Debit, Transport).
USE `pharma_ops`;

ALTER TABLE `purchases`
    ADD COLUMN IF NOT EXISTS `cash_credit` VARCHAR(20) NOT NULL DEFAULT 'Credit' AFTER `invoice_date`,
    ADD COLUMN IF NOT EXISTS `due_date` DATE NULL AFTER `cash_credit`,
    ADD COLUMN IF NOT EXISTS `cd_mode` VARCHAR(20) NULL DEFAULT 'Percentage' AFTER `due_date`,
    ADD COLUMN IF NOT EXISTS `cd_pct` DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER `cd_mode`,
    ADD COLUMN IF NOT EXISTS `cd_amount` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `cd_pct`,
    ADD COLUMN IF NOT EXISTS `transport_charge` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `net_amount`,
    ADD COLUMN IF NOT EXISTS `transport_gst_pct` DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER `transport_charge`,
    ADD COLUMN IF NOT EXISTS `transport_charge_amt` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `transport_gst_pct`,
    ADD COLUMN IF NOT EXISTS `debit_note_no` VARCHAR(60) NULL AFTER `transport_charge_amt`,
    ADD COLUMN IF NOT EXISTS `debit_deduction_amt` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `debit_note_no`,
    ADD COLUMN IF NOT EXISTS `bill_net_before_debit` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `debit_deduction_amt`,
    ADD COLUMN IF NOT EXISTS `paid` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `bill_net_before_debit`,
    ADD COLUMN IF NOT EXISTS `balance` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `paid`,
    ADD COLUMN IF NOT EXISTS `payment_status` VARCHAR(20) NOT NULL DEFAULT 'pending' AFTER `balance`;

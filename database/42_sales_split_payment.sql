-- Retail sales split payment (payment_mode = 'cash+upi'): cash part + UPI part of net_amount.
-- OPS DB (local pharma_ops / live gmpserver_advancedMaindb). Other modes keep both at 0.
ALTER TABLE `sales`
    ADD COLUMN IF NOT EXISTS `cash_amount` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `payment_mode`,
    ADD COLUMN IF NOT EXISTS `upi_amount`  DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `cash_amount`;

-- Bill net rounded to whole rupee (Retail Math.round parity): round_off = net_amount - unrounded net.
-- OPS DB (local / live gmpserver_advancedMaindb). Existing bills stay 0.
ALTER TABLE `sales`
    ADD COLUMN IF NOT EXISTS `round_off` DECIMAL(6,2) NOT NULL DEFAULT 0 AFTER `net_amount`;

-- Migration 15: chronic-patient flag on the customer master.
-- Patients are maintained in `customers` (keyed by phone within a client).

ALTER TABLE `customers`
    ADD COLUMN IF NOT EXISTS `is_chronic` TINYINT(1) NOT NULL DEFAULT 0 AFTER `gstin`;

-- Helps phone-based patient lookup during billing.
-- (idx_customer_phone already exists from the base schema.)

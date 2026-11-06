-- AuraGharTak public lookups (app order polling, tracking, live map).
-- Additive only: new secondary indexes, no column or data changes.
USE `pharma_ops`;

CREATE INDEX IF NOT EXISTS idx_agt_order_no_lookup ON agt_orders (order_no);
CREATE INDEX IF NOT EXISTS idx_agt_order_share_token ON agt_orders (share_token);
CREATE INDEX IF NOT EXISTS idx_agt_order_entry ON agt_orders (entry_date);

CREATE INDEX IF NOT EXISTS idx_order_phone ON online_orders (phone);
CREATE INDEX IF NOT EXISTS idx_order_created ON online_orders (created_at);

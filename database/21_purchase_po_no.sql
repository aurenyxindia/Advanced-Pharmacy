-- ============================================================
-- Purchase PO No (retail-style: PR/{client}/{yy}/{seq})
-- ============================================================
USE `pharma_ops`;

ALTER TABLE `purchases`
    ADD COLUMN IF NOT EXISTS `po_no` VARCHAR(60) NULL AFTER `branch_id`,
    ADD COLUMN IF NOT EXISTS `po_seq` INT UNSIGNED NULL AFTER `po_no`;

-- Unique per client when set
ALTER TABLE `purchases`
    ADD UNIQUE KEY IF NOT EXISTS `uq_purchase_po_no` (`client_id`, `po_no`);

-- Backfill existing rows: PR/{client_id}/{yy}/{seq} using creation year + per-client sequence
SET @prev_client := 0;
SET @seq := 0;

UPDATE `purchases` p
JOIN (
    SELECT
        id,
        client_id,
        @seq := IF(@prev_client = client_id, @seq + 1, 1) AS seq_no,
        @prev_client := client_id AS _c
    FROM `purchases`
    ORDER BY client_id ASC, id ASC
) s ON s.id = p.id
SET
    p.`po_seq` = s.seq_no,
    p.`po_no` = CONCAT(
        'PR/',
        p.`client_id`,
        '/',
        DATE_FORMAT(COALESCE(p.`created_at`, p.`invoice_date`, NOW()), '%y'),
        '/',
        s.seq_no
    )
WHERE p.`po_no` IS NULL OR p.`po_no` = '';

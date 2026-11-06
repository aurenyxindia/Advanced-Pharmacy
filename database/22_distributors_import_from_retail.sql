-- ============================================================
-- Import Retail `distributor` dump into Advanced `distributors` (master DB)
-- Prerequisite: load distributor.sql so table `distributor` exists in ops/main DB
-- Target: paperlesspharmac_advancedMaster, client_id = 1 (demo)
-- ============================================================
USE `paperlesspharmac_advancedMaster`;

SET @client_id := 1;

INSERT INTO `distributors` (
    `client_id`, `distributor_code`, `name`, `phone`, `contact_person`, `email`,
    `state`, `gstin`, `gst_state_code`, `address`,
    `dl_no1`, `dl_no2`, `dl_no3`, `dl_no4`, `gst_type`, `credit_limit_days`,
    `is_general`, `is_active`, `created_at`, `updated_at`
)
SELECT
    @client_id,
    NULLIF(TRIM(d.`d_no`), ''),
    NULLIF(LEFT(UPPER(TRIM(d.`d_name`)), 150), ''),
    NULLIF(LEFT(TRIM(d.`d_cont`), 20), ''),
    NULLIF(LEFT(TRIM(COALESCE(d.`cont_per`, '')), 120), ''),
    NULLIF(LEFT(TRIM(d.`d_mail`), 150), ''),
    NULLIF(LEFT(TRIM(d.`state`), 80), ''),
    CASE
        WHEN TRIM(COALESCE(d.`d_gst`, '')) REGEXP '^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$'
            THEN UPPER(TRIM(d.`d_gst`))
        ELSE NULL
    END,
    CASE
        WHEN TRIM(COALESCE(d.`gst_igst_code`, '')) REGEXP '^[0-9]{2}$' THEN TRIM(d.`gst_igst_code`)
        WHEN TRIM(COALESCE(d.`d_gst`, '')) REGEXP '^[0-9]{2}' THEN LEFT(TRIM(d.`d_gst`), 2)
        ELSE NULL
    END,
    NULLIF(LEFT(UPPER(TRIM(d.`d_add`)), 255), ''),
    NULLIF(LEFT(UPPER(TRIM(d.`d_lic`)), 60), ''),
    NULLIF(LEFT(UPPER(TRIM(d.`d_lic2`)), 60), ''),
    NULLIF(LEFT(UPPER(TRIM(d.`d_lic3`)), 60), ''),
    NULLIF(LEFT(UPPER(TRIM(d.`d_lic4`)), 60), ''),
    NULLIF(TRIM(d.`gst_type`), ''),
    CASE WHEN TRIM(COALESCE(d.`d_credit_limit_days`, '')) REGEXP '^[0-9]+$'
         THEN CAST(TRIM(d.`d_credit_limit_days`) AS UNSIGNED) ELSE NULL END,
    CASE WHEN LOWER(TRIM(COALESCE(d.`isgeneral`, 'no'))) = 'yes' THEN 1 ELSE 0 END,
    CASE WHEN LOWER(TRIM(COALESCE(d.`status`, 'Active'))) = 'active' THEN 1 ELSE 0 END,
    NOW(),
    NOW()
FROM `distributor` d
WHERE LOWER(TRIM(COALESCE(d.`status`, 'Active'))) = 'active'
  AND TRIM(COALESCE(d.`d_name`, '')) <> ''
  AND NOT EXISTS (
      SELECT 1 FROM `distributors` s
      WHERE s.`client_id` = @client_id
        AND UPPER(TRIM(s.`name`)) = UPPER(TRIM(d.`d_name`))
  );

-- Optional cleanup after successful import:
-- DROP TABLE IF EXISTS `distributor`;

SELECT COUNT(*) AS distributors_total FROM distributors WHERE client_id = @client_id;

-- ============================================================
-- pharma_ops : GPS for AuraGharTak (Advanced branches + Retail client_no)
-- Run on local pharma_ops and GMP gmpserver_advancedMaindb
-- ============================================================
USE `pharma_ops`;

CREATE TABLE IF NOT EXISTS `agt_store_locations` (
    `source`          ENUM('advanced','retail') NOT NULL,
    `store_key`       VARCHAR(32) NOT NULL COMMENT 'advanced=branch_id, retail=client_no',
    `latitude`        DECIMAL(10,7) NULL,
    `longitude`       DECIMAL(10,7) NULL,
    `geocode_source`  ENUM('manual','geocoded') NULL,
    `created_at`      DATETIME NOT NULL,
    `updated_at`      DATETIME NOT NULL,
    PRIMARY KEY (`source`, `store_key`),
    KEY `idx_agt_store_loc_coords` (`latitude`, `longitude`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

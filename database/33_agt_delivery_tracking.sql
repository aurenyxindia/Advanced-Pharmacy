-- ============================================================
-- pharma_ops : AuraGharTak live delivery tracking (rider + GPS)
-- Run on local pharma_ops and GMP gmpserver_advancedMaindb
-- Riders are HO users with role 'delivery' (users.role is VARCHAR — no HO change)
-- ============================================================
USE `pharma_ops`;

ALTER TABLE `agt_orders`
    ADD COLUMN IF NOT EXISTS `rider_user_id`   BIGINT UNSIGNED NULL COMMENT 'HO users.id (role delivery)',
    ADD COLUMN IF NOT EXISTS `rider_name`      VARCHAR(150) NULL,
    ADD COLUMN IF NOT EXISTS `rider_phone`     VARCHAR(20) NULL,
    ADD COLUMN IF NOT EXISTS `drop_latitude`   DECIMAL(10,7) NULL,
    ADD COLUMN IF NOT EXISTS `drop_longitude`  DECIMAL(10,7) NULL,
    ADD COLUMN IF NOT EXISTS `tracking_active` TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE `agt_orders`
    ADD INDEX IF NOT EXISTS `idx_agt_order_rider` (`client_id`, `rider_user_id`, `status`);

CREATE TABLE IF NOT EXISTS `agt_rider_locations` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id`      BIGINT UNSIGNED NOT NULL,
    `order_id`       BIGINT UNSIGNED NOT NULL,
    `rider_user_id`  BIGINT UNSIGNED NOT NULL,
    `latitude`       DECIMAL(10,7) NOT NULL,
    `longitude`      DECIMAL(10,7) NOT NULL,
    `accuracy_m`     DECIMAL(8,2) NULL,
    `speed_mps`      DECIMAL(8,2) NULL,
    `heading`        DECIMAL(6,2) NULL,
    `recorded_at`    DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_agt_rider_loc_order` (`order_id`, `id`),
    KEY `idx_agt_rider_loc_time` (`recorded_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

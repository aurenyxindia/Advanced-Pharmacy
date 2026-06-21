-- ============================================================
-- pharma_master : Global shared catalog (single source of truth)
-- Generic Master, Product Master, Schedule categories, Substitute map.
-- Shared by ALL clients - NOT tenant-keyed.
-- ============================================================
CREATE DATABASE IF NOT EXISTS `pharma_master`
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `pharma_master`;

SET FOREIGN_KEY_CHECKS = 0;

-- ---------- Schedule categories (drug classification) ----------
CREATE TABLE IF NOT EXISTS `schedule_categories` (
    `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code`                  VARCHAR(20) NOT NULL,
    `name`                  VARCHAR(120) NOT NULL,
    `requires_prescription` TINYINT(1) NOT NULL DEFAULT 0,
    `locked_after_sale`     TINYINT(1) NOT NULL DEFAULT 0,
    `description`           VARCHAR(255) NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_schedule_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Generic Master ----------
CREATE TABLE IF NOT EXISTS `generics` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`              VARCHAR(180) NOT NULL,
    `therapeutic_class` VARCHAR(120) NULL,
    `is_active`         TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`        DATETIME NOT NULL,
    `updated_at`        DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_generic_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Product Master ----------
CREATE TABLE IF NOT EXISTS `products` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`              VARCHAR(200) NOT NULL,
    `generic_id`        INT UNSIGNED NULL,
    `manufacturer`      VARCHAR(150) NULL,
    `schedule_category` ENUM('OTC','H','H1','X','G','RESTRICTED') NOT NULL DEFAULT 'OTC',
    `hsn_code`          VARCHAR(20) NULL,
    `default_gst_rate`  DECIMAL(5,2) NOT NULL DEFAULT 12.00,
    `pack_size`         VARCHAR(40) NULL,
    `uom`               VARCHAR(20) NULL DEFAULT 'NOS',
    `composition`       VARCHAR(255) NULL,
    `is_active`         TINYINT(1) NOT NULL DEFAULT 1,
    `created_by_client` BIGINT UNSIGNED NULL,
    `created_at`        DATETIME NOT NULL,
    `updated_at`        DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_product_name` (`name`),
    KEY `idx_product_generic` (`generic_id`),
    KEY `idx_product_schedule` (`schedule_category`),
    CONSTRAINT `fk_product_generic` FOREIGN KEY (`generic_id`) REFERENCES `generics`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Substitute / branded-generic mapping ----------
CREATE TABLE IF NOT EXISTS `substitutes` (
    `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `product_id`           INT UNSIGNED NOT NULL,
    `substitute_product_id` INT UNSIGNED NOT NULL,
    `generic_id`           INT UNSIGNED NULL,
    `created_at`           DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_substitute` (`product_id`,`substitute_product_id`),
    KEY `idx_sub_generic` (`generic_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------- Seed schedule categories ----------
INSERT INTO `schedule_categories` (`code`,`name`,`requires_prescription`,`locked_after_sale`,`description`) VALUES
    ('OTC','Over the Counter',0,0,'No prescription required'),
    ('H','Schedule H',1,1,'Prescription drugs - record retention required'),
    ('H1','Schedule H1',1,1,'Habit-forming / antibiotics - mandatory prescription + register'),
    ('X','Schedule X',1,1,'Narcotic/psychotropic - dual prescription copy + strict register'),
    ('G','Schedule G',1,1,'Caution: to be taken under medical supervision'),
    ('RESTRICTED','Restricted Drug',1,1,'Restricted category - prescription mandatory, immutable after sale')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

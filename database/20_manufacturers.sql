-- ============================================================
-- Manufacturer Master (global catalog in pharma_master)
-- Used by Product Master manufacturer dropdown (name + S Code).
-- ============================================================
USE `pharma_master`;

CREATE TABLE IF NOT EXISTS `manufacturers` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`       VARCHAR(150) NOT NULL,
    `s_code`     VARCHAR(20) NOT NULL,
    `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_manufacturer_name` (`name`),
    KEY `idx_manufacturer_scode` (`s_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed from existing product manufacturers (keep current short codes when present)
INSERT INTO `manufacturers` (`name`, `s_code`, `is_active`, `created_at`, `updated_at`)
SELECT
    UPPER(TRIM(p.`manufacturer`)) AS `name`,
    COALESCE(
        NULLIF(MAX(NULLIF(TRIM(p.`manufacturer_short`), '')), ''),
        UPPER(LEFT(REPLACE(TRIM(p.`manufacturer`), ' ', ''), 3))
    ) AS `s_code`,
    1,
    NOW(),
    NOW()
FROM `products` p
WHERE p.`manufacturer` IS NOT NULL AND TRIM(p.`manufacturer`) <> ''
GROUP BY UPPER(TRIM(p.`manufacturer`))
ON DUPLICATE KEY UPDATE
    `s_code` = IF(`manufacturers`.`s_code` = '' OR `manufacturers`.`s_code` IS NULL, VALUES(`s_code`), `manufacturers`.`s_code`),
    `updated_at` = NOW();

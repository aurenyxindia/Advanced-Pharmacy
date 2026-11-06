-- ============================================================
-- HSN Master (global catalog in pharma_master)
-- Retail parity: list + add HSN codes (4, 6, or 8 digits).
-- ============================================================
USE `pharma_master`;

CREATE TABLE IF NOT EXISTS `hsn_codes` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code`       VARCHAR(8) NOT NULL,
    `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_hsn_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed distinct valid HSN values already used on products.
INSERT IGNORE INTO `hsn_codes` (`code`, `is_active`, `created_at`, `updated_at`)
SELECT DISTINCT TRIM(p.`hsn_code`), 1, NOW(), NOW()
FROM `products` p
WHERE p.`hsn_code` IS NOT NULL
  AND TRIM(p.`hsn_code`) <> ''
  AND TRIM(p.`hsn_code`) REGEXP '^[0-9]{4}$|^[0-9]{6}$|^[0-9]{8}$';

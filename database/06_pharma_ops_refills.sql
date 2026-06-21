-- ============================================================
-- pharma_ops : Prescription refill reminders (Phase 7)
-- ============================================================
USE `pharma_ops`;

CREATE TABLE IF NOT EXISTS `refill_reminders` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id`     BIGINT UNSIGNED NOT NULL,
    `branch_id`     BIGINT UNSIGNED NOT NULL,
    `sale_id`       BIGINT UNSIGNED NULL,
    `customer_id`   BIGINT UNSIGNED NULL,
    `customer_name` VARCHAR(150) NULL,
    `phone`         VARCHAR(20) NULL,
    `product_name`  VARCHAR(200) NULL,
    `refill_date`   DATE NOT NULL,
    `status`        ENUM('pending','notified','completed','cancelled') NOT NULL DEFAULT 'pending',
    `channel`       ENUM('sms','email','call','whatsapp') NULL,
    `notes`         VARCHAR(255) NULL,
    `notified_at`   DATETIME NULL,
    `created_at`    DATETIME NOT NULL,
    `updated_at`    DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_refill_tenant` (`client_id`, `branch_id`),
    KEY `idx_refill_date` (`refill_date`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

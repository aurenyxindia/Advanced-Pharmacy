-- ============================================================
-- In-Patient (IPD) billing
--  * an admission accrues many sale bills until discharge
--  * partial payments recorded against the admission
--  * balance = total billed - total paid, settled at discharge
-- ============================================================
USE `pharma_ops`;

CREATE TABLE IF NOT EXISTS `ipd_admissions` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id`     BIGINT UNSIGNED NOT NULL,
    `branch_id`     BIGINT UNSIGNED NOT NULL,
    `admission_no`  VARCHAR(40) NOT NULL,
    `patient_name`  VARCHAR(150) NOT NULL,
    `contact_no`    VARCHAR(20) NULL,
    `address`       VARCHAR(255) NULL,
    `doctor_name`   VARCHAR(150) NULL,
    `ward`          VARCHAR(60) NULL,
    `bed_no`        VARCHAR(30) NULL,
    `status`        ENUM('admitted','discharged') NOT NULL DEFAULT 'admitted',
    `admitted_at`   DATETIME NOT NULL,
    `discharged_at` DATETIME NULL,
    `notes`         VARCHAR(255) NULL,
    `created_by`    BIGINT UNSIGNED NULL,
    `created_at`    DATETIME NOT NULL,
    `updated_at`    DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_admission_no` (`admission_no`),
    KEY `idx_ipd_tenant` (`client_id`, `branch_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ipd_payments` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id`    BIGINT UNSIGNED NOT NULL,
    `branch_id`    BIGINT UNSIGNED NOT NULL,
    `admission_id` BIGINT UNSIGNED NOT NULL,
    `amount`       DECIMAL(12,2) NOT NULL DEFAULT 0,
    `mode`         VARCHAR(20) NOT NULL DEFAULT 'cash',
    `note`         VARCHAR(255) NULL,
    `paid_at`      DATETIME NOT NULL,
    `created_by`   BIGINT UNSIGNED NULL,
    `created_at`   DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_ipd_pay` (`admission_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Link sales to an admission (NULL for normal OPD/retail/wholesale bills).
ALTER TABLE `sales`
    ADD COLUMN IF NOT EXISTS `ipd_admission_id` BIGINT UNSIGNED NULL AFTER `counter`;

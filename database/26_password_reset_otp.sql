-- Forgot-password OTP (Retail parity: otpCode on account).
USE `pharma_ho`;

ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `otp_code` VARCHAR(16) NULL AFTER `password_hash`,
    ADD COLUMN IF NOT EXISTS `otp_expires_at` DATETIME NULL AFTER `otp_code`;

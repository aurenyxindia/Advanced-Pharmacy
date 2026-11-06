<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\AuthService;
use App\Core\HttpException;
use App\Database\Connection;
use App\Models\User;

/**
 * Retail-style forgot password: 4-digit OTP emailed, then reset with OTP.
 */
final class PasswordResetService
{
    private User $users;
    private SmtpMailService $mail;

    public function __construct()
    {
        $this->users = new User();
        $this->mail = new SmtpMailService();
        $this->ensureSchema();
    }

    /**
     * @return array{email:string,notice?:string}
     */
    public function sendOtp(string $username): array
    {
        $username = trim($username);
        if ($username === '') {
            throw new HttpException('Please enter registered email or phone number.', 400);
        }

        $user = $this->users->findByEmailOrPhone($username);
        if (!$user) {
            throw new HttpException('Please enter registered email or phone number.', 400);
        }

        $email = trim((string) ($user['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new HttpException('Registered email is missing or invalid for this account.', 400);
        }

        if (($user['status'] ?? '') !== 'active') {
            throw HttpException::forbidden('Account is not active.');
        }

        $otp = (string) random_int(1000, 9999);
        $expires = date('Y-m-d H:i:s', time() + 900);

        $this->users->setOtp((int) $user['id'], $otp, $expires);

        $name = htmlspecialchars((string) ($user['name'] ?? 'User'), ENT_QUOTES, 'UTF-8');
        $subject = '[PaperLess Pharmacy] Password reset OTP';
        $html = 'Dear ' . $name
            . ', your one-time code for password change is: <strong>' . htmlspecialchars($otp, ENT_QUOTES, 'UTF-8')
            . '</strong>. Thanks &amp; Regards,<br><small style="color:#555;">Support ISD: +91</small>';
        $text = 'Dear ' . strip_tags((string) ($user['name'] ?? 'User'))
            . ', your one-time code for password change is: ' . $otp . '. Thanks.';

        $sent = $this->mail->sendHtml($email, $subject, $html, $text);
        if (!$sent['ok']) {
            throw new HttpException($sent['error'] ?? 'Could not send OTP email. Please try again or contact support.', 502);
        }

        return [
            'email'  => $email,
            'notice' => 'If you do not see it within a few minutes, check Spam/Junk and Promotions (Gmail).',
        ];
    }

    public function resetPassword(string $username, string $otp, string $password): void
    {
        $username = trim($username);
        $otp = trim($otp);
        if ($username === '' || $otp === '' || strlen($password) < 6) {
            throw HttpException::validation([
                'username'      => $username === '' ? ['Username is required.'] : [],
                'security_code' => $otp === '' ? ['OTP is required.'] : [],
                'password'      => strlen($password) < 6 ? ['Password must be at least 6 characters.'] : [],
            ]);
        }

        $user = $this->users->findByEmailOrPhone($username);
        if (!$user || !$this->users->otpMatches($user, $otp)) {
            throw new HttpException('Invalid OTP.', 400);
        }

        $this->users->update((int) $user['id'], [
            'password_hash' => AuthService::hashPassword($password),
            'otp_code'      => null,
            'otp_expires_at'=> null,
        ]);
    }

    private function ensureSchema(): void
    {
        try {
            Connection::ho()->exec(
                'ALTER TABLE `users`
                    ADD COLUMN IF NOT EXISTS `otp_code` VARCHAR(16) NULL AFTER `password_hash`,
                    ADD COLUMN IF NOT EXISTS `otp_expires_at` DATETIME NULL AFTER `otp_code`'
            );
        } catch (\Throwable) {
            // Non-fatal on older MySQL without IF NOT EXISTS — run database/26_password_reset_otp.sql manually.
        }
    }
}

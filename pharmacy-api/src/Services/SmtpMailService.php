<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Env;

/**
 * SMTP mail sender — same mailbox/provider as Retail forgotPassMain.php.
 * Uses native PHP sockets (no PHPMailer required).
 */
final class SmtpMailService
{
    private string $user;
    private string $pass;
    private string $host;
    private int $port;
    private string $secure;
    private string $fromName;
    private bool $bccSender;

    public function __construct()
    {
        $this->user = (string) Env::get('SMTP_USER', Env::get('PAPERLESS_SMTP_USER', 'retailwholesale@paperlesspharmacy.in'));
        $this->pass = (string) Env::get('SMTP_PASS', Env::get('PAPERLESS_SMTP_PASS', ''));
        $this->host = (string) Env::get('SMTP_HOST', 'paperlesspharmacy.in');
        $this->port = (int) Env::get('SMTP_PORT', 465);
        $this->secure = strtolower((string) Env::get('SMTP_SECURE', 'ssl'));
        $this->fromName = (string) Env::get('SMTP_FROM_NAME', 'PaperLess Pharmacy');
        $this->bccSender = Env::bool('SMTP_BCC_SENDER', true);
    }

    public function isConfigured(): bool
    {
        return $this->pass !== '' && $this->user !== '' && $this->host !== '';
    }

    /**
     * @return array{ok:bool,error?:string,host?:string,port?:int}
     */
    public function sendHtml(string $to, string $subject, string $htmlBody, string $textBody, ?string $replyName = null): array
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Invalid recipient email.'];
        }
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'SMTP is not configured (set SMTP_PASS or PAPERLESS_SMTP_PASS in .env).'];
        }

        $hosts = array_values(array_unique(array_filter([
            $this->host,
            'paperlesspharmacy.in',
            'mail.paperlesspharmacy.in',
        ])));

        $plans = [
            [$this->port, $this->secure],
            [587, 'tls'],
            [465, 'ssl'],
        ];
        $seen = [];
        $portPlans = [];
        foreach ($plans as [$p, $s]) {
            $key = $p . '|' . strtolower((string) $s);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $portPlans[] = [$p, strtolower((string) $s)];
            }
        }

        $recipients = [$to];
        if ($this->bccSender && filter_var($this->user, FILTER_VALIDATE_EMAIL)) {
            $recipients[] = $this->user;
        }

        $lastError = '';
        foreach ($hosts as $host) {
            foreach ($portPlans as [$port, $secure]) {
                $client = new NativeSmtpClient($host, $port, $secure, $this->user, $this->pass);
                $err = $client->send(
                    $this->user,
                    $this->fromName,
                    $recipients,
                    $subject,
                    $htmlBody,
                    $textBody,
                    $replyName ?? $this->fromName,
                );
                if ($err === null) {
                    return ['ok' => true, 'host' => $host, 'port' => $port];
                }
                $lastError = $err;
            }
        }

        return ['ok' => false, 'error' => $lastError !== '' ? $lastError : 'Could not send email.'];
    }
}

/** Minimal SMTP client for AUTH LOGIN + HTML mail. */
final class NativeSmtpClient
{
    private $socket;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $secure,
        private readonly string $user,
        private readonly string $pass,
    ) {
    }

    public function send(
        string $fromEmail,
        string $fromName,
        array $toEmails,
        string $subject,
        string $htmlBody,
        string $textBody,
        string $replyName,
    ): ?string {
        $transport = ($this->secure === 'ssl' ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->port;
        $errno = 0;
        $errstr = '';
        $this->socket = @stream_socket_client(
            $transport,
            $errno,
            $errstr,
            45,
            STREAM_CLIENT_CONNECT,
            stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ],
            ]),
        );
        if (!$this->socket) {
            return "Connect failed: {$errstr} ({$errno})";
        }

        try {
            $this->expect(220);
            $this->cmd('EHLO paperlesspharmacy.in', 250);

            if ($this->secure === 'tls') {
                $this->cmd('STARTTLS', 220);
                if (!stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    return 'STARTTLS failed.';
                }
                $this->cmd('EHLO paperlesspharmacy.in', 250);
            }

            $this->cmd('AUTH LOGIN', 334);
            $this->cmd(base64_encode($this->user), 334);
            $this->cmd(base64_encode($this->pass), 235);

            $this->cmd('MAIL FROM:<' . $fromEmail . '>', 250);
            foreach ($toEmails as $rcpt) {
                $this->cmd('RCPT TO:<' . $rcpt . '>', 250);
            }

            $boundary = 'b_' . bin2hex(random_bytes(8));
            $headers = [
                'From: ' . $this->encodeAddress($fromEmail, $fromName),
                'Reply-To: ' . $this->encodeAddress($fromEmail, $replyName),
                'To: ' . implode(', ', $toEmails),
                'Subject: ' . $this->encodeHeader($subject),
                'MIME-Version: 1.0',
                'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
                'Date: ' . date('r'),
            ];

            $body = "--{$boundary}\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n\r\n"
                . $this->normalize($textBody) . "\r\n"
                . "--{$boundary}\r\n"
                . "Content-Type: text/html; charset=UTF-8\r\n\r\n"
                . $this->normalize($htmlBody) . "\r\n"
                . "--{$boundary}--\r\n";

            $this->cmd('DATA', 354);
            $this->write(implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.");
            $this->expect(250);
            $this->cmd('QUIT', 221);
            return null;
        } catch (\Throwable $e) {
            return $e->getMessage();
        } finally {
            if (is_resource($this->socket)) {
                fclose($this->socket);
            }
        }
    }

    private function cmd(string $command, int $expectCode): void
    {
        $this->write($command);
        $this->expect($expectCode);
    }

    private function write(string $data): void
    {
        fwrite($this->socket, $data . "\r\n");
    }

    private function expect(int $code): void
    {
        $line = '';
        while (($buf = fgets($this->socket, 515)) !== false) {
            $line .= $buf;
            if (isset($buf[3]) && $buf[3] === ' ') {
                break;
            }
        }
        $got = (int) substr(trim($line), 0, 3);
        if ($got !== $code) {
            throw new \RuntimeException(trim($line) !== '' ? trim($line) : "Expected SMTP {$code}");
        }
    }

    private function encodeHeader(string $value): string
    {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private function encodeAddress(string $email, string $name): string
    {
        $safeName = str_replace(['"', "\r", "\n"], '', $name);
        return $this->encodeHeader($safeName) . " <{$email}>";
    }

    private function normalize(string $text): string
    {
        return preg_replace("/\r\n|\r|\n/", "\r\n", $text) ?? $text;
    }
}

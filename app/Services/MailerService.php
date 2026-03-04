<?php declare(strict_types=1);

namespace Devana\Services;

final class MailerService
{
    private \DB\SQL $db;

    public function __construct(\DB\SQL $db)
    {
        $this->db = $db;
    }

    public function isEnabled(): bool
    {
        return (int) $this->getConfig('mail_enabled', '0') === 1;
    }

    public function isPhpMailerAvailable(): bool
    {
        return class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')
            && class_exists('\\PHPMailer\\PHPMailer\\SMTP')
            && class_exists('\\PHPMailer\\PHPMailer\\Exception');
    }

    /**
     * @return true|string True on success, otherwise error message.
     */
    public function send(string $toEmail, string $subject, string $body): bool|string
    {
        if (!$this->isEnabled()) {
            return 'Mail system is disabled.';
        }
        if (!$this->isPhpMailerAvailable()) {
            return 'PHPMailer class is not installed.';
        }

        $host = trim($this->getConfig('smtp_host', ''));
        $port = max(1, (int) $this->getConfig('smtp_port', '587'));
        $username = trim($this->getConfig('smtp_username', ''));
        $password = (string) $this->getConfig('smtp_password', '');
        $secure = strtolower(trim($this->getConfig('smtp_secure', 'tls')));
        $fromEmail = trim($this->getConfig('smtp_from_email', ''));
        $fromName = trim($this->getConfig('smtp_from_name', 'Devana'));
        $smtpAuth = (int) $this->getConfig('smtp_auth', '1') === 1;

        if ($host === '' || $fromEmail === '') {
            return 'SMTP host and from email are required.';
        }

        try {
            $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mailer->isSMTP();
            $mailer->Host = $host;
            $mailer->Port = $port;
            $mailer->SMTPAuth = $smtpAuth;
            if ($smtpAuth) {
                $mailer->Username = $username;
                $mailer->Password = $password;
            }

            if ($secure === 'ssl') {
                $mailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($secure === 'tls') {
                $mailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mailer->SMTPSecure = '';
                $mailer->SMTPAutoTLS = false;
            }

            $mailer->CharSet = 'UTF-8';
            $mailer->setFrom($fromEmail, $fromName !== '' ? $fromName : 'Devana');
            $mailer->addAddress($toEmail);
            $mailer->Subject = $subject;
            $mailer->Body = $body;
            $mailer->isHTML(false);
            $mailer->send();

            return true;
        } catch (\Throwable $e) {
            return 'SMTP send failed: ' . $e->getMessage();
        }
    }

    private function getConfig(string $name, string $default = ''): string
    {
        $row = $this->db->exec('SELECT value FROM config WHERE name = ? ORDER BY ord ASC LIMIT 1', [$name]);
        return (string) ($row[0]['value'] ?? $default);
    }
}

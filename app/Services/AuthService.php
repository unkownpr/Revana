<?php declare(strict_types=1);

namespace Devana\Services;

final class AuthService
{
    private \DB\SQL $db;

    private const DEFAULT_MAX_ATTEMPTS = 5;
    private const DEFAULT_LOCK_MINUTES = 15;

    public function __construct(\DB\SQL $db)
    {
        $this->db = $db;
    }

    public function authenticate(string $username, string $password): ?array
    {
        $result = $this->db->exec(
            'SELECT * FROM users WHERE name = ? AND level > 0 LIMIT 1',
            [$username]
        );

        if (empty($result)) {
            return null;
        }

        $user = $result[0];

        if (!password_verify($password, $user['pass'])) {
            return null;
        }

        return $user;
    }

    public function isUserTaken(string $username, string $email, string $ip): bool
    {
        $result = $this->db->exec(
            'SELECT COUNT(*) AS cnt FROM users WHERE name = ? OR email = ? OR ip = ?',
            [$username, $email, $ip]
        );

        return (int) ($result[0]['cnt'] ?? 0) > 0;
    }

    public function register(
        string $username,
        string $password,
        string $email,
        int $factionId
    ): bool {
        $passwordHash = password_hash($password, PASSWORD_BCRYPT);
        $defaultLang = $this->getConfigString('default_lang', 'en.php');
        if (!preg_match('/^[a-z]{2}\.php$/', $defaultLang)) {
            $defaultLang = 'en.php';
        }

        try {
            $this->db->exec(
                'INSERT INTO users (name, pass, email, level, joined, lastVisit, points, ip, grPath, faction, alliance, rank, sitter, description, lang)
                 VALUES (?, ?, ?, 1, NOW(), NOW(), 0, ?, ?, ?, 0, 0, \'\', \'\', ?)',
                [
                    $username,
                    $passwordHash,
                    $email,
                    $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                    'default/',
                    $factionId,
                    $defaultLang,
                ]
            );

            return true;
        } catch (\PDOException $e) {
            return false;
        }
    }

    public function findById(int $id): ?array
    {
        $result = $this->db->exec(
            'SELECT * FROM users WHERE id = ? LIMIT 1',
            [$id]
        );

        return $result[0] ?? null;
    }

    public function resetPassword(int $userId, string $newPasswordHash): void
    {
        $this->db->exec(
            'UPDATE users SET pass = ? WHERE id = ?',
            [$newPasswordHash, $userId]
        );
    }

    public function findByUsername(string $username): ?array
    {
        $result = $this->db->exec(
            'SELECT * FROM users WHERE name = ? AND level > 0 LIMIT 1',
            [$username]
        );
        return $result[0] ?? null;
    }

    /**
     * Returns true if the account is currently locked out.
     */
    public function isLocked(string $username): bool
    {
        $result = $this->db->exec(
            'SELECT locked_until FROM users WHERE name = ? AND level > 0 LIMIT 1',
            [$username]
        );
        if (empty($result) || $result[0]['locked_until'] === null) {
            return false;
        }
        return strtotime($result[0]['locked_until']) > time();
    }

    /**
     * Increment failed login counter; lock account after MAX_ATTEMPTS.
     */
    public function recordFailedAttempt(string $username): void
    {
        $result = $this->db->exec(
            'SELECT id, login_attempts FROM users WHERE name = ? AND level > 0 LIMIT 1',
            [$username]
        );
        if (empty($result)) {
            return;
        }

        $id = (int) $result[0]['id'];
        $attempts = (int) $result[0]['login_attempts'] + 1;
        $maxAttempts = max(1, $this->getConfigInt('login_max_attempts', self::DEFAULT_MAX_ATTEMPTS));
        $lockMinutes = max(1, $this->getConfigInt('login_lock_minutes', self::DEFAULT_LOCK_MINUTES));

        if ($attempts >= $maxAttempts) {
            $this->db->exec(
                'UPDATE users SET login_attempts = ?, locked_until = DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE id = ?',
                [$attempts, $lockMinutes, $id]
            );
        } else {
            $this->db->exec(
                'UPDATE users SET login_attempts = ? WHERE id = ?',
                [$attempts, $id]
            );
        }
    }

    /**
     * Reset failed counter and lock after successful login.
     */
    public function resetFailedAttempts(int $userId): void
    {
        $this->db->exec(
            'UPDATE users SET login_attempts = 0, locked_until = NULL WHERE id = ?',
            [$userId]
        );
    }

    private function getConfigInt(string $name, int $default): int
    {
        $rows = $this->db->exec('SELECT value FROM config WHERE name = ? ORDER BY ord ASC LIMIT 1', [$name]);
        if (empty($rows)) {
            return $default;
        }
        return (int) ($rows[0]['value'] ?? $default);
    }

    private function getConfigString(string $name, string $default): string
    {
        $rows = $this->db->exec('SELECT value FROM config WHERE name = ? ORDER BY ord ASC LIMIT 1', [$name]);
        if (empty($rows)) {
            return $default;
        }
        return (string) ($rows[0]['value'] ?? $default);
    }

}

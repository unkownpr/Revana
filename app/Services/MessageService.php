<?php

declare(strict_types=1);

namespace Devana\Services;

final class MessageService
{
    private \DB\SQL $db;

    public function __construct(\DB\SQL $db)
    {
        $this->db = $db;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getInbox(int $userId): array
    {
        return $this->db->exec(
            'SELECT m.*, u.name AS senderName FROM messages m LEFT JOIN users u ON m.sender = u.id WHERE m.recipient = ? ORDER BY m.sent DESC',
            [$userId]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $result = $this->db->exec(
            'SELECT m.*, u.name AS senderName FROM messages m LEFT JOIN users u ON m.sender = u.id WHERE m.id = ?',
            [$id]
        );

        return $result[0] ?? null;
    }

    public function send(
        int $senderId,
        int $recipientId,
        string $subject,
        string $contents
    ): bool {
        if ($this->isBlocked($recipientId, $senderId)) {
            return false;
        }

        $this->db->exec(
            'INSERT INTO messages (sender, recipient, subject, contents, sent) VALUES (?, ?, ?, ?, NOW())',
            [$senderId, $recipientId, $subject, $contents]
        );
        return true;
    }

    public function findRecipientIdByName(string $rawName): ?int
    {
        $name = $this->normalizeRecipientName($rawName);
        if ($name === '') {
            return null;
        }

        // Fast path: exact byte match for legacy varbinary usernames.
        $exact = $this->db->exec(
            'SELECT id FROM users WHERE name = ? LIMIT 1',
            [$name]
        );
        if (!empty($exact)) {
            return (int) $exact[0]['id'];
        }

        // Fallback: case-insensitive comparison for better UX.
        $fallback = $this->db->exec(
            'SELECT id FROM users WHERE LOWER(CAST(name AS CHAR)) = LOWER(?) LIMIT 1',
            [$name]
        );

        if (!empty($fallback)) {
            return (int) $fallback[0]['id'];
        }

        // Final fallback: normalize both sides in PHP to handle odd whitespace
        // and copied invisible characters in recipient input.
        $rows = $this->db->exec('SELECT id, name FROM users');
        $needle = mb_strtolower($name, 'UTF-8');
        foreach ($rows as $row) {
            $candidate = $this->normalizeRecipientName((string) ($row['name'] ?? ''));
            if ($candidate !== '' && mb_strtolower($candidate, 'UTF-8') === $needle) {
                return (int) $row['id'];
            }
        }

        return null;
    }

    private function normalizeRecipientName(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Remove common zero-width characters.
        $value = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $value) ?? $value;
        // Collapse all unicode whitespace into single spaces.
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        return trim($value);
    }

    public function isBlocked(int $recipientId, int $senderId): bool
    {
        $row = $this->db->exec(
            'SELECT 1 FROM blocklist WHERE recipient = ? AND sender = ? LIMIT 1',
            [$recipientId, $senderId]
        );

        return !empty($row);
    }

    public function delete(int $messageId, int $userId): void
    {
        $this->db->exec(
            'DELETE FROM messages WHERE id = ? AND recipient = ?',
            [$messageId, $userId]
        );
    }

    public function deleteAll(int $userId): void
    {
        $this->db->exec('DELETE FROM messages WHERE recipient = ?', [$userId]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getReports(int $userId): array
    {
        return $this->db->exec(
            'SELECT * FROM reports WHERE recipient = ? ORDER BY sent DESC',
            [$userId]
        );
    }

    public function deleteReport(int $reportId, int $userId): void
    {
        $this->db->exec(
            'DELETE FROM reports WHERE id = ? AND recipient = ?',
            [$reportId, $userId]
        );
    }

    public function deleteAllReports(int $userId): void
    {
        $this->db->exec('DELETE FROM reports WHERE recipient = ?', [$userId]);
    }


}

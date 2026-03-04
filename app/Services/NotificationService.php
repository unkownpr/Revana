<?php declare(strict_types=1);

namespace Devana\Services;

final class NotificationService
{
    // Notification types
    public const TYPE_ARMY_RETURN    = 0;
    public const TYPE_ATTACK_INCOMING = 1;
    public const TYPE_BUILD_DONE     = 2;
    public const TYPE_UNIT_DONE      = 3;
    public const TYPE_MISSION_REWARD = 4;
    public const TYPE_STREAK_REWARD  = 5;
    public const TYPE_ACHIEVEMENT    = 6;
    public const TYPE_SEASON_END     = 7;
    public const TYPE_WAR_DECLARED   = 8;

    private \DB\SQL $db;

    public function __construct(\DB\SQL $db)
    {
        $this->db = $db;
    }

    public function notify(int $userId, int $type, string $msgKey, string $url = ''): void
    {
        if ($userId <= 0) {
            return;
        }

        try {
            $this->db->exec(
                'INSERT INTO notifications (user_id, type, message_key, url, is_read, created_at)
                 VALUES (?, ?, ?, ?, 0, NOW())',
                [$userId, $type, $msgKey, $url]
            );
        } catch (\Throwable $e) {
            // non-critical
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getUnread(int $userId, int $limit = 20): array
    {
        return $this->db->exec(
            'SELECT id, type, message_key, url, created_at
             FROM notifications
             WHERE user_id = ? AND is_read = 0
             ORDER BY created_at DESC
             LIMIT ?',
            [$userId, $limit]
        ) ?: [];
    }

    public function getUnreadCount(int $userId): int
    {
        $rows = $this->db->exec(
            'SELECT COUNT(*) AS cnt FROM notifications WHERE user_id = ? AND is_read = 0',
            [$userId]
        );
        return (int) ($rows[0]['cnt'] ?? 0);
    }

    public function markAllRead(int $userId): void
    {
        $this->db->exec(
            'UPDATE notifications SET is_read = 1 WHERE user_id = ?',
            [$userId]
        );
    }

    public function markOneRead(int $userId, int $id): void
    {
        $this->db->exec(
            'UPDATE notifications SET is_read = 1 WHERE user_id = ? AND id = ?',
            [$userId, $id]
        );
    }

    public function pruneOld(int $days = 30): void
    {
        $this->db->exec(
            'DELETE FROM notifications WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
            [$days]
        );
    }
}

<?php

declare(strict_types=1);

namespace Devana\Models;

final class Message extends \DB\SQL\Mapper
{
    private \DB\SQL $database;

    public function __construct(\DB\SQL $db)
    {
        parent::__construct($db, 'messages');
        $this->database = $db;
    }

    public function findById(int $id): ?array
    {
        $this->load(['id = ?', $id]);

        return $this->dry() ? null : $this->cast();
    }

    public function findByRecipient(int $userId): array
    {
        return $this->database->exec(
            'SELECT * FROM messages WHERE recipient = ? ORDER BY sent DESC',
            [$userId]
        );
    }

    public function countUnread(int $userId): int
    {
        $result = $this->database->exec(
            'SELECT COUNT(*) AS total FROM messages WHERE recipient = ?',
            [$userId]
        );

        return (int) ($result[0]['total'] ?? 0);
    }
}

<?php

declare(strict_types=1);

namespace Devana\Services;

final class ChatService
{
    private \DB\SQL $db;

    public function __construct(\DB\SQL $db)
    {
        $this->db = $db;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRooms(): array
    {
        return $this->db->exec('SELECT * FROM chat_s ORDER BY id');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getMessages(int $roomId, int $limit = 50): array
    {
        return $this->db->exec(
            'SELECT c.*, u.name AS senderName, u.email AS senderEmail, u.avatar_seed AS senderAvatarSeed, u.avatar_style AS senderAvatarStyle, u.avatar_options AS senderAvatarOptions FROM chat c LEFT JOIN users u ON c.sender = u.id WHERE c.sId = ? ORDER BY c.timeStamp DESC LIMIT ?',
            [$roomId, $limit]
        );
    }

    public function sendMessage(
        int $roomId,
        int $senderId,
        int $recipientId,
        string $message
    ): void {
        $this->db->exec(
            'INSERT INTO chat (sId, timeStamp, message, recipient, sender) VALUES (?, NOW(), ?, ?, ?)',
            [$roomId, $message, $recipientId, $senderId]
        );
    }

    public function createRoom(string $name): void
    {
        $this->db->exec('INSERT INTO chat_s (name) VALUES (?)', [$name]);
    }

    public function deleteRoom(int $roomId): void
    {
        $this->db->exec('DELETE FROM chat WHERE sId = ?', [$roomId]);
        $this->db->exec('DELETE FROM chat_s WHERE id = ?', [$roomId]);
    }
}

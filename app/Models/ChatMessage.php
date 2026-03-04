<?php

declare(strict_types=1);

namespace Devana\Models;

final class ChatMessage extends \DB\SQL\Mapper
{
    private \DB\SQL $database;

    public function __construct(\DB\SQL $db)
    {
        parent::__construct($db, 'chat');
        $this->database = $db;
    }

    public function findByRoom(int $roomId): array
    {
        return $this->database->exec(
            'SELECT * FROM chat WHERE sId = ? ORDER BY timeStamp ASC',
            [$roomId]
        );
    }
}

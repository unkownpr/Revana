<?php

declare(strict_types=1);

namespace Devana\Models;

final class ChatRoom extends \DB\SQL\Mapper
{
    private \DB\SQL $database;

    public function __construct(\DB\SQL $db)
    {
        parent::__construct($db, 'chat_s');
        $this->database = $db;
    }

    public function findById(int $id): ?array
    {
        $this->load(['id = ?', $id]);

        return $this->dry() ? null : $this->cast();
    }

    public function all(): array
    {
        return $this->database->exec('SELECT * FROM chat_s ORDER BY id');
    }
}

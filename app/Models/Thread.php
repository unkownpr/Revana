<?php

declare(strict_types=1);

namespace Devana\Models;

final class Thread extends \DB\SQL\Mapper
{
    private \DB\SQL $database;

    public function __construct(\DB\SQL $db)
    {
        parent::__construct($db, 'threads');
        $this->database = $db;
    }

    public function findById(int $id): ?array
    {
        $this->load(['id = ?', $id]);

        return $this->dry() ? null : $this->cast();
    }

    public function findByForum(int $forumId): array
    {
        return $this->database->exec(
            'SELECT * FROM threads WHERE forum = ? ORDER BY date DESC',
            [$forumId]
        );
    }
}

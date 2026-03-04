<?php

declare(strict_types=1);

namespace Devana\Models;

final class Post extends \DB\SQL\Mapper
{
    private \DB\SQL $database;

    public function __construct(\DB\SQL $db)
    {
        parent::__construct($db, 'posts');
        $this->database = $db;
    }

    public function findById(int $id): ?array
    {
        $this->load(['id = ?', $id]);

        return $this->dry() ? null : $this->cast();
    }

    public function findByThread(int $threadId): array
    {
        return $this->database->exec(
            'SELECT * FROM posts WHERE thread = ? ORDER BY date ASC',
            [$threadId]
        );
    }
}

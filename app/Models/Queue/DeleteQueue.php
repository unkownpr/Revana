<?php

declare(strict_types=1);

namespace Devana\Models\Queue;

final class DeleteQueue extends \DB\SQL\Mapper
{
    private \DB\SQL $database;

    public function __construct(\DB\SQL $db)
    {
        parent::__construct($db, 'd_queue');
        $this->database = $db;
    }

    public function findByUser(int $userId): ?array
    {
        $this->load(['user = ?', $userId]);

        return $this->dry() ? null : $this->cast();
    }
}

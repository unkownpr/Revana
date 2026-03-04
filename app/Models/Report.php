<?php

declare(strict_types=1);

namespace Devana\Models;

final class Report extends \DB\SQL\Mapper
{
    private \DB\SQL $database;

    public function __construct(\DB\SQL $db)
    {
        parent::__construct($db, 'reports');
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
            'SELECT * FROM reports WHERE recipient = ? ORDER BY sent DESC',
            [$userId]
        );
    }
}

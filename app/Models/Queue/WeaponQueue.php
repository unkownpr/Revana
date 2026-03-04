<?php

declare(strict_types=1);

namespace Devana\Models\Queue;

final class WeaponQueue extends \DB\SQL\Mapper
{
    private \DB\SQL $database;

    public function __construct(\DB\SQL $db)
    {
        parent::__construct($db, 'w_queue');
        $this->database = $db;
    }

    public function findByTown(int $townId): array
    {
        return $this->database->exec(
            'SELECT * FROM w_queue WHERE town = ? ORDER BY dueTime ASC',
            [$townId]
        );
    }
}

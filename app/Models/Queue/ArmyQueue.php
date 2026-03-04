<?php

declare(strict_types=1);

namespace Devana\Models\Queue;

final class ArmyQueue extends \DB\SQL\Mapper
{
    private \DB\SQL $database;

    public function __construct(\DB\SQL $db)
    {
        parent::__construct($db, 'a_queue');
        $this->database = $db;
    }

    public function findByTown(int $townId): array
    {
        return $this->database->exec(
            'SELECT * FROM a_queue WHERE town = ? ORDER BY dueTime ASC',
            [$townId]
        );
    }

    public function findByTarget(int $targetId): array
    {
        return $this->database->exec(
            'SELECT * FROM a_queue WHERE target = ? ORDER BY dueTime ASC',
            [$targetId]
        );
    }
}

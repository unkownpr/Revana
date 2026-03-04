<?php

declare(strict_types=1);

namespace Devana\Models\Queue;

final class BuildQueue extends \DB\SQL\Mapper
{
    private \DB\SQL $database;

    public function __construct(\DB\SQL $db)
    {
        parent::__construct($db, 'c_queue');
        $this->database = $db;
    }

    public function findByTown(int $townId): array
    {
        return $this->database->exec(
            'SELECT * FROM c_queue WHERE town = ? ORDER BY dueTime ASC',
            [$townId]
        );
    }
}

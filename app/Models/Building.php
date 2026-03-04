<?php

declare(strict_types=1);

namespace Devana\Models;

final class Building extends \DB\SQL\Mapper
{
    private \DB\SQL $database;

    public function __construct(\DB\SQL $db)
    {
        parent::__construct($db, 'buildings');
        $this->database = $db;
    }

    public function findByTypeAndFaction(int $type, int $faction): ?array
    {
        $this->load(['type = ? AND faction = ?', $type, $faction]);

        return $this->dry() ? null : $this->cast();
    }

    public function findByFaction(int $faction): array
    {
        return $this->database->exec(
            'SELECT * FROM buildings WHERE faction = ? ORDER BY type',
            [$faction]
        );
    }
}

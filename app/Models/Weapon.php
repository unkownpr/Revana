<?php

declare(strict_types=1);

namespace Devana\Models;

final class Weapon extends \DB\SQL\Mapper
{
    private \DB\SQL $database;

    public function __construct(\DB\SQL $db)
    {
        parent::__construct($db, 'weapons');
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
            'SELECT * FROM weapons WHERE faction = ? ORDER BY type',
            [$faction]
        );
    }
}

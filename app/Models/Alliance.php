<?php

declare(strict_types=1);

namespace Devana\Models;

final class Alliance extends \DB\SQL\Mapper
{
    private \DB\SQL $database;

    public function __construct(\DB\SQL $db)
    {
        parent::__construct($db, 'alliances');
        $this->database = $db;
    }

    public function findById(int $id): ?array
    {
        $this->load(['id = ?', $id]);

        return $this->dry() ? null : $this->cast();
    }

    public function findByName(string $name): ?array
    {
        $this->load(['name = ?', $name]);

        return $this->dry() ? null : $this->cast();
    }

    public function getMembers(\DB\SQL $db, int $allianceId): array
    {
        return $db->exec(
            'SELECT * FROM users WHERE alliance = ? ORDER BY name',
            [$allianceId]
        );
    }
}

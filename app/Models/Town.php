<?php

declare(strict_types=1);

namespace Devana\Models;

final class Town extends \DB\SQL\Mapper
{
    private \DB\SQL $database;

    public function __construct(\DB\SQL $db)
    {
        parent::__construct($db, 'towns');
        $this->database = $db;
    }

    public function findById(int $id): ?array
    {
        $this->load(['id = ?', $id]);

        return $this->dry() ? null : $this->cast();
    }

    public function findByOwner(int $ownerId): array
    {
        return $this->database->exec(
            'SELECT * FROM towns WHERE owner = ? ORDER BY id',
            [$ownerId]
        );
    }

    public function getCapital(int $ownerId): ?array
    {
        $this->load(['owner = ? AND isCapital = 1', $ownerId]);

        return $this->dry() ? null : $this->cast();
    }

    public function updateResources(int $id, string $resources, string $lastCheck): void
    {
        $this->database->exec(
            'UPDATE towns SET resources = ?, lastCheck = ? WHERE id = ?',
            [$resources, $lastCheck, $id]
        );
    }
}

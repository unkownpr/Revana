<?php

declare(strict_types=1);

namespace Devana\Models;

final class User extends \DB\SQL\Mapper
{
    private \DB\SQL $database;

    public function __construct(\DB\SQL $db)
    {
        parent::__construct($db, 'users');
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

    public function isNameOrEmailTaken(string $name, string $email): bool
    {
        $this->load(['name = ? OR email = ?', $name, $email]);

        return !$this->dry();
    }

    public function findByFaction(int $factionId): array
    {
        return $this->database->exec(
            'SELECT * FROM users WHERE faction = ? ORDER BY id',
            [$factionId]
        );
    }

    public function updatePoints(int $id, int $points): void
    {
        $this->database->exec(
            'UPDATE users SET points = ? WHERE id = ?',
            [$points, $id]
        );
    }
}

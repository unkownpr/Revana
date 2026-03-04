<?php

declare(strict_types=1);

namespace Devana\Models;

final class Forum extends \DB\SQL\Mapper
{
    private \DB\SQL $database;

    public function __construct(\DB\SQL $db)
    {
        parent::__construct($db, 'forums');
        $this->database = $db;
    }

    public function findById(int $id): ?array
    {
        $this->load(['id = ?', $id]);

        return $this->dry() ? null : $this->cast();
    }

    public function findByAlliance(int $allianceId): array
    {
        return $this->database->exec(
            'SELECT * FROM forums WHERE alliance = ? ORDER BY id',
            [$allianceId]
        );
    }
}

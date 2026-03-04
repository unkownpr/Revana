<?php

declare(strict_types=1);

namespace Devana\Models;

final class Pact extends \DB\SQL\Mapper
{
    private \DB\SQL $database;

    public function __construct(\DB\SQL $db)
    {
        parent::__construct($db, 'pacts');
        $this->database = $db;
    }

    public function findByAlliance(int $allianceId): array
    {
        return $this->database->exec(
            'SELECT * FROM pacts WHERE a1 = ? OR a2 = ? ORDER BY type',
            [$allianceId, $allianceId]
        );
    }
}

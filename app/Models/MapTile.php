<?php

declare(strict_types=1);

namespace Devana\Models;

final class MapTile extends \DB\SQL\Mapper
{
    private \DB\SQL $database;

    public function __construct(\DB\SQL $db)
    {
        parent::__construct($db, 'map');
        $this->database = $db;
    }

    public function findByCoords(int $x, int $y): ?array
    {
        $this->load(['x = ? AND y = ?', $x, $y]);

        return $this->dry() ? null : $this->cast();
    }

    public function findTownTile(int $townId): ?array
    {
        $this->load(['type = ? AND subtype = ?', 'town', $townId]);

        return $this->dry() ? null : $this->cast();
    }

    public function getRange(int $minX, int $maxX, int $minY, int $maxY): array
    {
        return $this->database->exec(
            'SELECT * FROM map WHERE x >= ? AND x <= ? AND y >= ? AND y <= ? ORDER BY x, y',
            [$minX, $maxX, $minY, $maxY]
        );
    }
}

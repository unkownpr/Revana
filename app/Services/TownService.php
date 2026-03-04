<?php declare(strict_types=1);

namespace Devana\Services;

use Devana\Helpers\DataParser;

final class TownService
{
    private \DB\SQL $db;

    public function __construct(\DB\SQL $db)
    {
        $this->db = $db;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $result = $this->db->exec('SELECT * FROM towns WHERE id = ?', [$id]);

        return $result[0] ?? null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByOwner(int $ownerId): array
    {
        return $this->db->exec(
            'SELECT * FROM towns WHERE owner = ? ORDER BY isCapital DESC',
            [$ownerId]
        );
    }

    public function isOwnedBy(int $townId, int $userId): bool
    {
        $result = $this->db->exec('SELECT owner FROM towns WHERE id = ?', [$townId]);

        return !empty($result) && (int) $result[0]['owner'] === $userId;
    }

    /**
     * @return array<int, int>
     */
    public function parseBuildingLevels(string $buildings): array
    {
        return DataParser::toIntArray($buildings);
    }

    /**
     * @return array{crop: float, lumber: float, stone: float, iron: float, gold: float}
     */
    public function parseResources(string $resources): array
    {
        return DataParser::parseResources($resources);
    }

    /**
     * @return array{crop: int, resources: int, gold: int}
     */
    public function parseLimits(string $limits): array
    {
        $parts = DataParser::toIntArray($limits);

        return [
            'crop'      => $parts[0] ?? 800,
            'resources' => $parts[1] ?? 600,
            'gold'      => $parts[2] ?? 500,
            'housing'   => $parts[3] ?? 0,
        ];
    }

    /**
     * @return array<int, int>
     */
    public function parseArmy(string $army): array
    {
        return DataParser::toIntArray($army);
    }

    /**
     * @return array<int, int>
     */
    public function parseWeapons(string $weapons): array
    {
        return DataParser::toIntArray($weapons);
    }

    /**
     * @return array{crop: float, lumber: float, stone: float, iron: float, gold: float}
     */
    public function parseProduction(string $production): array
    {
        $parts = DataParser::toFloatArray($production);

        return [
            'crop'   => $parts[0] ?? 0.0,
            'lumber' => $parts[1] ?? 0.0,
            'stone'  => $parts[2] ?? 0.0,
            'iron'   => $parts[3] ?? 0.0,
            'gold'   => $parts[4] ?? 0.0,
        ];
    }

    /**
     * @return array<int, array<int, int>>
     */
    public function parseLand(string $land): array
    {
        return array_map(
            fn(string $s): array => DataParser::toIntArray($s),
            explode('/', $land)
        );
    }

    public function updateName(int $townId, string $name): void
    {
        $this->db->exec('UPDATE towns SET name = ? WHERE id = ?', [$name, $townId]);
    }

    public function updateDescription(int $townId, string $description): void
    {
        $this->db->exec('UPDATE towns SET description = ? WHERE id = ?', [$description, $townId]);
    }

    public function setCapital(int $userId, int $townId): void
    {
        $this->db->exec('UPDATE towns SET isCapital = 0 WHERE owner = ?', [$userId]);
        $this->db->exec(
            'UPDATE towns SET isCapital = 1 WHERE id = ? AND owner = ?',
            [$townId, $userId]
        );
    }

    /**
     * Get town coordinates from map.
     *
     * @return array{x: string, y: string}|null
     */
    public function getTownCoords(int $townId): ?array
    {
        $result = $this->db->exec(
            'SELECT x, y FROM map WHERE type = 3 AND subtype = ?',
            [$townId]
        );

        return $result[0] ?? null;
    }

    /**
     * Get first town ID for a user.
     */
    public function getFirstTownId(int $userId): ?int
    {
        $result = $this->db->exec('SELECT id FROM towns WHERE owner = ? LIMIT 1', [$userId]);

        return !empty($result) ? (int) $result[0]['id'] : null;
    }

    /**
     * Create a new town with default values.
     */
    public function createTown(int $ownerId, string $name): int
    {
        $defaults = [
            'buildings' => '0-0-0-0-0-0-0-0-0-0-0-0-0-0-0-0-0-0-0-0-0-0',
            'resources' => '500-500-500-500-500',
            'limits' => '800-600-500-0-0-0-0-0-0-0-0-0-0',
            'production' => '0-0-0-0-0',
            'army' => '0-0-0-0-0-0-0-0-0-0-0-0-0',
            'weapons' => '0-0-0-0-0-0-0-0-0-0-0',
            'general' => '0-0-0-0-0',
            'land' => '0-0-0-0/0-0-0-0/0-0-0-0/0-0-0-0',
            'uUpgrades' => '0-0-0-0-0-0-0-0-0-0-0-0-0',
            'wUpgrades' => '0-0-0-0-0-0-0-0-0-0-0-0-0',
            'aUpgrades' => '0-0-0-0-0-0-0-0-0-0-0-0-0',
        ];

        $this->db->exec(
            'INSERT INTO towns (name, owner, isCapital, buildings, resources, `limits`, production, army, weapons, general, land, population, upkeep, morale, description, uUpgrades, wUpgrades, aUpgrades) VALUES (?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 100, \'\', ?, ?, ?)',
            [
                $name, $ownerId,
                $defaults['buildings'], $defaults['resources'], $defaults['limits'],
                $defaults['production'], $defaults['army'], $defaults['weapons'],
                $defaults['general'], $defaults['land'],
                $defaults['uUpgrades'], $defaults['wUpgrades'], $defaults['aUpgrades'],
            ]
        );

        $result = $this->db->exec('SELECT LAST_INSERT_ID() AS id');
        return (int) $result[0]['id'];
    }

    /**
     * @return array<int, array{x: int, y: int}>
     */
    public function getDefaultPositions(): array
    {
        return [
            0  => ['x' => 53,  'y' => 30],
            1  => ['x' => 80,  'y' => 75],
            2  => ['x' => 12,  'y' => 140],
            3  => ['x' => 143, 'y' => 2],
            4  => ['x' => 0,   'y' => 60],
            5  => ['x' => 478, 'y' => 40],
            6  => ['x' => 555, 'y' => 26],
            7  => ['x' => 335, 'y' => 95],
            8  => ['x' => 187, 'y' => 105],
            9  => ['x' => 270, 'y' => 153],
            10 => ['x' => 437, 'y' => 125],
            11 => ['x' => 447, 'y' => 182],
            12 => ['x' => 32,  'y' => 230],
            13 => ['x' => 205, 'y' => 110],
            14 => ['x' => 567, 'y' => 225],
            15 => ['x' => 215, 'y' => 5],
            16 => ['x' => 102, 'y' => 153],
            17 => ['x' => 333, 'y' => 220],
            18 => ['x' => 192, 'y' => 213],
            19 => ['x' => 300, 'y' => 0],
            20 => ['x' => 380, 'y' => 0],
            21 => ['x' => 526, 'y' => 123],
        ];
    }

    /**
     * @return array{background: string, positions: array<int, array{x: int, y: int}>}
     */
    public function parseLayout(?string $layout): array
    {
        $defaults = $this->getDefaultPositions();
        $result = ['background' => 'back.png', 'positions' => $defaults];

        if ($layout === null || $layout === '') {
            return $result;
        }

        $data = json_decode($layout, true);
        if (!is_array($data)) {
            return $result;
        }

        if (isset($data['background']) && is_string($data['background'])) {
            $result['background'] = $data['background'];
        }

        if (isset($data['positions']) && is_array($data['positions'])) {
            foreach ($data['positions'] as $idx => $pos) {
                $i = (int) $idx;
                if ($i < 0 || $i > 21 || !is_array($pos)) {
                    continue;
                }
                $x = isset($pos['x']) ? (int) $pos['x'] : ($defaults[$i]['x'] ?? 0);
                $y = isset($pos['y']) ? (int) $pos['y'] : ($defaults[$i]['y'] ?? 0);
                $result['positions'][$i] = [
                    'x' => max(0, min(565, $x)),
                    'y' => max(0, min(272, $y)),
                ];
            }
        }

        return $result;
    }

    public function updateLayout(int $townId, string $layoutJson): void
    {
        $this->db->exec('UPDATE towns SET layout = ? WHERE id = ?', [$layoutJson, $townId]);
    }

    /**
     * Move an owned town to a new resource-land tile.
     *
     * @return array{ok: bool, message: string}
     */
    public function moveTown(int $townId, int $ownerId, int $targetX, int $targetY, int $cropCost = 100): array
    {
        $townRows = $this->db->exec(
            'SELECT id, owner, resources FROM towns WHERE id = ? LIMIT 1',
            [$townId]
        );
        if (empty($townRows) || (int) $townRows[0]['owner'] !== $ownerId) {
            return ['ok' => false, 'message' => 'Town not found.'];
        }

        $sourceTile = $this->db->exec(
            'SELECT x, y FROM map WHERE type = 3 AND subtype = ? LIMIT 1',
            [$townId]
        );
        if (empty($sourceTile)) {
            return ['ok' => false, 'message' => 'Current town location not found.'];
        }

        $fromX = (int) $sourceTile[0]['x'];
        $fromY = (int) $sourceTile[0]['y'];
        if ($fromX === $targetX && $fromY === $targetY) {
            return ['ok' => false, 'message' => 'Target is current location.'];
        }

        $targetTile = $this->db->exec(
            'SELECT type FROM map WHERE x = ? AND y = ? LIMIT 1',
            [$targetX, $targetY]
        );
        if (empty($targetTile)) {
            return ['ok' => false, 'message' => 'Target tile not found.'];
        }
        if ((int) $targetTile[0]['type'] !== 1) {
            return ['ok' => false, 'message' => 'You can only move to resource land.'];
        }

        $resources = DataParser::toFloatArray((string) $townRows[0]['resources']);
        $currentCrop = (float) ($resources[0] ?? 0.0);
        if ($currentCrop < $cropCost) {
            return ['ok' => false, 'message' => 'Not enough crop to move town.'];
        }

        $resources[0] = $currentCrop - $cropCost;

        $this->db->begin();
        try {
            $this->db->exec(
                'UPDATE towns SET resources = ? WHERE id = ? AND owner = ?',
                [DataParser::serializeRounded($resources), $townId, $ownerId]
            );

            // Old town location becomes regular resource land again.
            $this->db->exec(
                'UPDATE map SET type = 1, subtype = ? WHERE x = ? AND y = ? AND type = 3 AND subtype = ?',
                [rand(1, 4), $fromX, $fromY, $townId]
            );

            // Claim target tile.
            $this->db->exec(
                'UPDATE map SET type = 3, subtype = ? WHERE x = ? AND y = ? AND type = 1',
                [$townId, $targetX, $targetY]
            );

            $verify = $this->db->exec(
                'SELECT 1 FROM map WHERE x = ? AND y = ? AND type = 3 AND subtype = ? LIMIT 1',
                [$targetX, $targetY, $townId]
            );
            if (empty($verify)) {
                throw new \RuntimeException('Target tile was changed concurrently.');
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            return ['ok' => false, 'message' => 'Move failed.'];
        }

        return ['ok' => true, 'message' => 'Town moved successfully.'];
    }

    public function getCapital(int $ownerId): ?array
    {
        $result = $this->db->exec(
            'SELECT * FROM towns WHERE owner = ? AND isCapital = 1 LIMIT 1',
            [$ownerId]
        );
        return $result[0] ?? null;
    }

    public function recalculatePopulation(int $townId, int $factionId): void
    {
        $townRows = $this->db->exec('SELECT buildings, land FROM towns WHERE id = ? LIMIT 1', [$townId]);
        if (empty($townRows)) {
            return;
        }

        $town = $townRows[0];
        $buildingLevels = DataParser::toIntArray((string) ($town['buildings'] ?? ''));
        $land = $this->parseLand((string) ($town['land'] ?? ''));
        $buildings = $this->db->exec(
            'SELECT type, upkeep FROM buildings WHERE faction = ? ORDER BY type',
            [$factionId]
        );

        $upkeepByType = [];
        foreach ($buildings as $row) {
            $upkeepByType[(int) $row['type']] = DataParser::toIntArray((string) ($row['upkeep'] ?? ''));
        }

        $population = 0;

        foreach ($buildingLevels as $type => $level) {
            $population += $this->populationContribution($upkeepByType[(int) $type] ?? [], (int) $level);
        }

        for ($type = 0; $type <= 3; $type++) {
            $slots = $land[$type] ?? [];
            foreach ($slots as $level) {
                $population += $this->populationContribution($upkeepByType[$type] ?? [], (int) $level);
            }
        }

        $this->db->exec(
            'UPDATE towns SET population = ? WHERE id = ?',
            [max(0, $population), $townId]
        );
    }

    /**
     * @param array<int, int> $upkeepLevels
     */
    private function populationContribution(array $upkeepLevels, int $level): int
    {
        if ($level <= 0) {
            return 0;
        }
        return (int) ($upkeepLevels[$level - 1] ?? 0);
    }
}

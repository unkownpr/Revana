<?php

declare(strict_types=1);

namespace Devana\Services;

final class BuildingService
{
    private \DB\SQL $db;

    public function __construct(\DB\SQL $db)
    {
        $this->db = $db;
    }

    /**
     * Get all building data for a faction.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getByFaction(int $factionId): array
    {
        return $this->db->exec(
            'SELECT * FROM buildings WHERE faction = ? ORDER BY type',
            [$factionId]
        );
    }

    /**
     * Get single building by type and faction.
     *
     * @return array<string, mixed>|null
     */
    public function getByTypeAndFaction(int $type, int $factionId): ?array
    {
        $result = $this->db->exec(
            'SELECT * FROM buildings WHERE type = ? AND faction = ?',
            [$type, $factionId]
        );

        return $result[0] ?? null;
    }

    /**
     * Check if building requirements are met.
     *
     * Requirements format: "7-3" means building type 7 at level 3.
     * Multiple: "16-1/17-1/21-1" means type 16 lvl 1 AND type 17 lvl 1 AND type 21 lvl 1.
     *
     * @param array<int, int> $buildingLevels
     */
    public function requirementsMet(string $requirements, array $buildingLevels): bool
    {
        if (empty($requirements)) {
            return true;
        }

        $reqs = explode('/', $requirements);

        foreach ($reqs as $req) {
            $parts = explode('-', $req);
            $type = (int)$parts[0];
            $level = (int)($parts[1] ?? 1);

            if (($buildingLevels[$type] ?? 0) < $level) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get cost for building at specific level.
     *
     * @return array<int, int>
     */
    public function getCost(string $inputStr): array
    {
        return array_map('intval', explode('-', $inputStr));
    }

    /**
     * Get duration for specific level.
     *
     * Duration format: "0:5-0:10-0:17-0:27-0:39-0:50-1:15-1:45-2:20-3:0" (per level).
     */
    public function getDuration(string $durationStr, int $level): string
    {
        $durations = explode('-', $durationStr);

        if ($level < 0 || $level >= count($durations)) {
            return '0:0';
        }

        return $durations[$level] ?? '0:0';
    }


}

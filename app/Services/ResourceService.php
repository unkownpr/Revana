<?php declare(strict_types=1);

namespace Devana\Services;

use Devana\Helpers\DataParser;

final class ResourceService
{
    private \DB\SQL $db;

    public function __construct(\DB\SQL $db)
    {
        $this->db = $db;
    }

    /**
     * Calculate and update resources based on time elapsed since lastCheck.
     */
    public function updateResources(int $townId): void
    {
        $row = $this->db->exec(
            'SELECT owner, production, resources, `limits`, TIMESTAMPDIFF(SECOND, lastCheck, NOW()) AS elapsed, morale, upkeep, population FROM towns WHERE id = ?',
            [$townId]
        );

        if (empty($row)) {
            return;
        }

        $town = $row[0];
        $elapsed = (int) $town['elapsed'] / 3600;
        $res = DataParser::toFloatArray($town['resources']);
        $prod = DataParser::toFloatArray($town['production']);
        $lim = DataParser::toFloatArray($town['limits']);
        $morale = (float) $town['morale'] / 100;

        $cropNet = self::netCropProduction($prod[0], (int) $town['population'], (int) $town['upkeep']);

        $res[0] = min($res[0] + $cropNet * $elapsed * $morale, $lim[0]);

        for ($i = 1; $i <= 3; $i++) {
            $res[$i] = min($res[$i] + $prod[$i] * $elapsed * $morale, $lim[1]);
        }

        $goldBefore = $res[4];
        $res[4] = min($res[4] + $prod[4] * $elapsed, $lim[2]);
        $goldGained = (int) ($res[4] - $goldBefore);

        $this->db->exec(
            'UPDATE towns SET resources = ?, lastCheck = NOW() WHERE id = ?',
            [DataParser::serializeRounded($res), $townId]
        );

        // Track gold production toward TYPE_GOLD daily mission.
        $ownerId = (int) ($town['owner'] ?? 0);
        if ($goldGained > 0 && $ownerId > 0) {
            (new MissionService($this->db))->incrementProgress($ownerId, MissionService::TYPE_GOLD, $goldGained);
        }
    }

    /**
     * Calculate net crop production with noob protection minimum.
     */
    public static function netCropProduction(float $cropProd, int $population, int $upkeep): float
    {
        return max(5.0, $cropProd - $population - $upkeep);
    }

    /**
     * Check if town has enough resources for a cost.
     */
    public function hasEnough(string $resources, string $cost): bool
    {
        $res = DataParser::toFloatArray($resources);
        $costArr = DataParser::toFloatArray($cost);

        for ($i = 0; $i < count($costArr); $i++) {
            if (($res[$i] ?? 0) < $costArr[$i]) {
                return false;
            }
        }

        return true;
    }

    /**
     * Deduct resources from town.
     */
    public function deductResources(int $townId, string $cost): void
    {
        $row = $this->db->exec('SELECT resources FROM towns WHERE id = ?', [$townId]);

        if (empty($row)) {
            return;
        }

        $res = DataParser::toFloatArray($row[0]['resources']);
        $costArr = DataParser::toFloatArray($cost);

        for ($i = 0; $i < count($costArr); $i++) {
            $res[$i] = max(0, ($res[$i] ?? 0) - $costArr[$i]);
        }

        $this->db->exec(
            'UPDATE towns SET resources = ? WHERE id = ?',
            [DataParser::serializeRounded($res), $townId]
        );
    }

    /**
     * Add resources to a town, capped at storage limits.
     * Limit mapping (same as updateResources):
     *   limits[0] = crop cap, limits[1] = lumber/stone/iron cap, limits[2] = gold cap
     */
    public function addResources(int $townId, array $amounts): void
    {
        $row = $this->db->exec('SELECT resources, `limits` FROM towns WHERE id = ?', [$townId]);

        if (empty($row)) {
            return;
        }

        $res = DataParser::toFloatArray($row[0]['resources']);
        $lim = DataParser::toFloatArray($row[0]['limits']);

        // Map each resource index to its storage limit index.
        $limitIdx = [0 => 0, 1 => 1, 2 => 1, 3 => 1, 4 => 2];
        for ($i = 0; $i < count($amounts); $i++) {
            $cap = $lim[$limitIdx[$i] ?? 1] ?? PHP_FLOAT_MAX;
            $res[$i] = min(($res[$i] ?? 0) + ($amounts[$i] ?? 0), $cap);
        }

        $this->db->exec(
            'UPDATE towns SET resources = ? WHERE id = ?',
            [DataParser::serializeRounded($res), $townId]
        );
    }

}

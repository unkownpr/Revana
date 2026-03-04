<?php

declare(strict_types=1);

namespace Devana\Services;

final class MapService
{
    private \DB\SQL $db;

    public function __construct(\DB\SQL $db)
    {
        $this->db = $db;
    }

    /**
     * Get map tiles in a range around a center point.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRange(int $centerX, int $centerY, int $radius): array
    {
        $minX = max(0, $centerX - $radius);
        $maxX = $centerX + $radius;
        $minY = max(0, $centerY - $radius);
        $maxY = $centerY + $radius;

        return $this->db->exec(
            'SELECT * FROM map WHERE x >= ? AND x <= ? AND y >= ? AND y <= ? ORDER BY y, x',
            [$minX, $maxX, $minY, $maxY]
        );
    }

    /**
     * Get tile detail at specific coordinates.
     *
     * @return array<string, mixed>|null
     */
    public function getTile(int $x, int $y): ?array
    {
        $result = $this->db->exec(
            'SELECT * FROM map WHERE x = ? AND y = ?',
            [$x, $y]
        );

        return $result[0] ?? null;
    }

    /**
     * Get town tile coordinates.
     *
     * @return array{x: string, y: string}|null
     */
    public function getTownLocation(int $townId): ?array
    {
        $result = $this->db->exec(
            'SELECT x, y FROM map WHERE type = 3 AND subtype = ?',
            [$townId]
        );

        return $result[0] ?? null;
    }

    /**
     * Calculate Euclidean distance between two points.
     */
    public function calculateDistance(int $x1, int $y1, int $x2, int $y2): float
    {
        return sqrt(pow($x2 - $x1, 2) + pow($y2 - $y1, 2));
    }

    /**
     * Calculate travel time in seconds based on distance and speed.
     */
    public function calculateTravelTime(float $distance, int $speed): int
    {
        if ($speed <= 0) {
            return PHP_INT_MAX;
        }

        return (int)ceil($distance / $speed * 3600);
    }

    /**
     * Find an available land tile for settling.
     *
     * @return array{x: string, y: string}|null
     */
    public function findAvailableLandTile(): ?array
    {
        $result = $this->db->exec(
            'SELECT x, y FROM map WHERE type = 1 ORDER BY RAND() LIMIT 1'
        );

        return $result[0] ?? null;
    }

    /**
     * Place town on map tile.
     */
    public function placeTown(int $townId, int $x, int $y): void
    {
        $this->db->exec(
            'UPDATE map SET type = 3, subtype = ? WHERE x = ? AND y = ?',
            [$townId, $x, $y]
        );
    }

    /**
     * Build 3×3 isometric tile data centred on (cx, cy) for mini-map previews.
     *
     * @return array<int, array<string, mixed>>
     */
    public function buildMiniMap(int $cx, int $cy, string $imgs): array
    {
        $range = 1;
        $tiles = $this->getRange($cx, $cy, $range);
        $tileMap = [];
        foreach ($tiles as $t) {
            $tileMap[$t['x'] . ',' . $t['y']] = $t;
        }

        $result = [];
        for ($k = $range; $k >= -$range; $k--) {
            for ($j = -$range; $j <= $range; $j++) {
                $tx  = $cx + $j;
                $ty  = $cy + $k;
                $stX = ($k + $range) * 40 + ($j + $range) * 40;
                $stY = ($range - $k) * 20 + ($j + $range) * 20;

                $tile = $tileMap[$tx . ',' . $ty] ?? null;
                $type = $tile ? (int) $tile['type'] : 0;
                $sub  = $tile ? (int) $tile['subtype'] : 0;

                if ($type === 3) {
                    $img = 'env_31.gif';
                } elseif ($type === 2) {
                    $img = 'env_2' . ($sub > 0 ? $sub : 1) . '.gif';
                } elseif ($type === 1) {
                    $img = 'env_1' . ($sub > 0 ? $sub : 1) . '.gif';
                } else {
                    $img = 'env_01.gif';
                }

                $result[] = [
                    'st_x'      => $stX,
                    'st_y'      => $stY,
                    'img'       => $imgs . 'map/' . $img,
                    'is_center' => ($j === 0 && $k === 0),
                ];
            }
        }
        return $result;
    }
}

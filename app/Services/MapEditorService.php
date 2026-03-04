<?php

declare(strict_types=1);

namespace Devana\Services;

final class MapEditorService
{
    private \DB\SQL $db;

    public function __construct(\DB\SQL $db)
    {
        $this->db = $db;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listMaps(): array
    {
        return $this->db->exec(
            'SELECT m.*, u.name AS creator_name
             FROM editor_maps m
             LEFT JOIN users u ON m.created_by = u.id
             ORDER BY m.id DESC'
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getMap(int $id): ?array
    {
        $result = $this->db->exec('SELECT * FROM editor_maps WHERE id = ?', [$id]);
        return $result[0] ?? null;
    }

    public function createMap(string $name, int $width, int $height, int $createdBy): int
    {
        $this->db->exec(
            'INSERT INTO editor_maps (name, width, height, created_by) VALUES (?, ?, ?, ?)',
            [$name, $width, $height, $createdBy]
        );

        $mapId = (int) $this->db->lastInsertId();

        // Batch insert tiles - all plains (type=1, subtype=0) by default
        $batchSize = 500;
        $values = [];
        $params = [];

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $values[] = '(?,?,?,1,0)';
                $params[] = $mapId;
                $params[] = $x;
                $params[] = $y;

                if (count($values) >= $batchSize) {
                    $this->db->exec(
                        'INSERT INTO editor_map_tiles (map_id, x, y, type, subtype) VALUES ' . implode(',', $values),
                        $params
                    );
                    $values = [];
                    $params = [];
                }
            }
        }

        if (!empty($values)) {
            $this->db->exec(
                'INSERT INTO editor_map_tiles (map_id, x, y, type, subtype) VALUES ' . implode(',', $values),
                $params
            );
        }

        return $mapId;
    }

    public function deleteMap(int $id): void
    {
        $this->db->exec('DELETE FROM editor_maps WHERE id = ?', [$id]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getTilesInRange(int $mapId, int $centerX, int $centerY, int $radius): array
    {
        $minX = max(0, $centerX - $radius);
        $maxX = $centerX + $radius;
        $minY = max(0, $centerY - $radius);
        $maxY = $centerY + $radius;

        return $this->db->exec(
            'SELECT * FROM editor_map_tiles WHERE map_id = ? AND x >= ? AND x <= ? AND y >= ? AND y <= ? ORDER BY y, x',
            [$mapId, $minX, $maxX, $minY, $maxY]
        );
    }

    public function setTile(int $mapId, int $x, int $y, int $type, int $subtype): void
    {
        $this->db->exec(
            'UPDATE editor_map_tiles SET type = ?, subtype = ? WHERE map_id = ? AND x = ? AND y = ?',
            [$type, $subtype, $mapId, $x, $y]
        );
    }

    public function activateMap(int $mapId): string
    {
        $map = $this->getMap($mapId);
        if (!$map) {
            return 'Map not found.';
        }

        $width = (int) $map['width'];
        $height = (int) $map['height'];

        // Check for out-of-bounds towns
        $outOfBounds = $this->db->exec(
            'SELECT x, y FROM map WHERE type = 3 AND (x >= ? OR y >= ?)',
            [$width, $height]
        );
        if (!empty($outOfBounds)) {
            return 'Cannot activate: ' . count($outOfBounds) . ' town(s) are outside this map\'s bounds (' . $width . 'x' . $height . ').';
        }

        // Save current town tiles
        $townTiles = $this->db->exec('SELECT x, y, type, subtype FROM map WHERE type = 3');

        // Clear current map
        $this->db->exec('DELETE FROM map');

        // Copy editor tiles to game map
        $this->db->exec(
            'INSERT INTO map (x, y, type, subtype)
             SELECT x, y, type, subtype FROM editor_map_tiles WHERE map_id = ?',
            [$mapId]
        );

        // Restore town tiles
        foreach ($townTiles as $tt) {
            $this->db->exec(
                'INSERT INTO map (x, y, type, subtype) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE type = VALUES(type), subtype = VALUES(subtype)',
                [$tt['x'], $tt['y'], $tt['type'], $tt['subtype']]
            );
        }

        // Update is_active flags
        $this->db->exec('UPDATE editor_maps SET is_active = 0');
        $this->db->exec('UPDATE editor_maps SET is_active = 1 WHERE id = ?', [$mapId]);

        return '';
    }

    public function ensureTileBrushesTable(): void
    {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS tile_brushes (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                type INT UNSIGNED NOT NULL,
                subtype INT UNSIGNED NOT NULL,
                name VARCHAR(50) NOT NULL,
                img_file VARCHAR(100) NOT NULL,
                is_default TINYINT(1) NOT NULL DEFAULT 0,
                sort_order INT UNSIGNED NOT NULL DEFAULT 0,
                lang_key VARCHAR(50) DEFAULT NULL,
                name_translations TEXT DEFAULT NULL,
                UNIQUE KEY (type, subtype)
            )'
        );

        // Add columns if missing (for existing tables)
        $columns = $this->db->exec('SHOW COLUMNS FROM tile_brushes');
        $colNames = array_column($columns, 'Field');
        if (!in_array('lang_key', $colNames, true)) {
            $this->db->exec('ALTER TABLE tile_brushes ADD COLUMN lang_key VARCHAR(50) DEFAULT NULL');
        }
        if (!in_array('name_translations', $colNames, true)) {
            $this->db->exec('ALTER TABLE tile_brushes ADD COLUMN name_translations TEXT DEFAULT NULL');
        }

        $count = $this->db->exec('SELECT COUNT(*) AS cnt FROM tile_brushes');
        if ((int) $count[0]['cnt'] === 0) {
            $defaults = [
                [0, 0, 'Water',    'env_01.gif', 1, 0, 'water'],
                [1, 0, 'Plains',   'env_11.gif', 1, 1, 'plains'],
                [1, 1, 'Crop',     'env_11.gif', 1, 2, 'crop'],
                [1, 2, 'Lumber',   'env_12.gif', 1, 3, 'lumber'],
                [1, 3, 'Stone',    'env_13.gif', 1, 4, 'stone'],
                [1, 4, 'Iron',     'env_14.gif', 1, 5, 'iron'],
                [2, 0, 'Mountain', 'env_21.gif', 1, 6, 'mountain'],
            ];
            foreach ($defaults as $d) {
                $this->db->exec(
                    'INSERT INTO tile_brushes (type, subtype, name, img_file, is_default, sort_order, lang_key) VALUES (?,?,?,?,?,?,?)',
                    $d
                );
            }
        } else {
            // Backfill lang_key for existing default brushes
            $langKeys = [
                'Water' => 'water', 'Plains' => 'plains', 'Crop' => 'crop',
                'Lumber' => 'lumber', 'Stone' => 'stone', 'Iron' => 'iron', 'Mountain' => 'mountain',
            ];
            foreach ($langKeys as $name => $key) {
                $this->db->exec(
                    'UPDATE tile_brushes SET lang_key = ? WHERE name = ? AND is_default = 1 AND lang_key IS NULL',
                    [$key, $name]
                );
            }
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAllBrushes(): array
    {
        return $this->db->exec('SELECT * FROM tile_brushes ORDER BY sort_order');
    }

    /**
     * @return array<string, string> e.g. {'0_0': 'env_01', '1_2': 'env_12'}
     */
    public function getBrushImageMap(): array
    {
        $rows = $this->db->exec('SELECT type, subtype, img_file FROM tile_brushes');
        $map = [];
        foreach ($rows as $r) {
            $map[$r['type'] . '_' . $r['subtype']] = $r['img_file'];
        }
        return $map;
    }

    /**
     * @param array<string, mixed> $lang Language array from loaded language file
     * @return array<string, string> e.g. {'0_0': 'Su', '1_2': 'Kereste'}
     */
    public function getTranslatedBrushNameMap(array $lang, string $langCode = 'en'): array
    {
        $rows = $this->db->exec('SELECT type, subtype, name, lang_key, name_translations FROM tile_brushes');
        $map = [];
        foreach ($rows as $r) {
            $key = $r['type'] . '_' . $r['subtype'];
            $translated = $r['name']; // fallback

            // Default brushes: use lang_key from language file
            if (!empty($r['lang_key']) && isset($lang[$r['lang_key']])) {
                $translated = $lang[$r['lang_key']];
            }
            // Custom brushes: use name_translations JSON
            elseif (!empty($r['name_translations'])) {
                $translations = json_decode($r['name_translations'], true);
                if (is_array($translations) && isset($translations[$langCode])) {
                    $translated = $translations[$langCode];
                }
            }

            $map[$key] = $translated;
        }
        return $map;
    }

    public function addBrush(int $type, int $subtype, string $name, string $imgFile, string $translations = '{}'): void
    {
        $maxSort = $this->db->exec('SELECT MAX(sort_order) AS mx FROM tile_brushes');
        $nextSort = ((int) ($maxSort[0]['mx'] ?? 0)) + 1;

        $this->db->exec(
            'INSERT INTO tile_brushes (type, subtype, name, img_file, is_default, sort_order, name_translations) VALUES (?,?,?,?,0,?,?)',
            [$type, $subtype, $name, $imgFile, $nextSort, $translations]
        );
    }

    /**
     * @return array<string, mixed>|null The deleted brush row (for file cleanup), or null if not deletable
     */
    public function deleteBrush(int $id): ?array
    {
        $rows = $this->db->exec('SELECT * FROM tile_brushes WHERE id = ? AND is_default = 0', [$id]);
        if (empty($rows)) {
            return null;
        }
        $this->db->exec('DELETE FROM tile_brushes WHERE id = ? AND is_default = 0', [$id]);
        return $rows[0];
    }

    public function getNextSubtype(int $type): int
    {
        $result = $this->db->exec('SELECT MAX(subtype) AS mx FROM tile_brushes WHERE type = ?', [$type]);
        return ((int) ($result[0]['mx'] ?? -1)) + 1;
    }

    public function generateRandom(int $mapId): void
    {
        $map = $this->getMap($mapId);
        if (!$map) {
            return;
        }

        $this->ensureTileBrushesTable();

        $width = (int) $map['width'];
        $height = (int) $map['height'];

        $typePools = [
            0 => [0],           // Water fallbacks
            1 => [0, 1, 2, 3, 4], // Land fallbacks
            2 => [0],           // Mountain fallbacks
        ];
        $rows = $this->db->exec('SELECT type, subtype FROM tile_brushes ORDER BY sort_order, id');
        foreach ($rows as $r) {
            $t = (int) ($r['type'] ?? -1);
            $s = (int) ($r['subtype'] ?? 0);
            if (!isset($typePools[$t])) {
                continue;
            }
            if (!in_array($s, $typePools[$t], true)) {
                $typePools[$t][] = $s;
            }
        }

        $pickSubtype = static function (array $pool, int $fallback = 0): int {
            if (empty($pool)) {
                return $fallback;
            }
            $idx = array_rand($pool);
            return (int) $pool[$idx];
        };

        // Delete existing tiles
        $this->db->exec('DELETE FROM editor_map_tiles WHERE map_id = ?', [$mapId]);

        // Generate random terrain: 15% water, 10% mountain, 75% land (including custom land subtypes)
        $batchSize = 500;
        $values = [];
        $params = [];

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $roll = rand(1, 100);

                if ($roll <= 15) {
                    // Water
                    $type = 0;
                    $subtype = $pickSubtype($typePools[0], 0);
                } elseif ($roll <= 25) {
                    // Mountain
                    $type = 2;
                    $subtype = $pickSubtype($typePools[2], 0);
                } else {
                    // Land (default and custom)
                    $type = 1;
                    $subtype = $pickSubtype($typePools[1], 0);
                }

                $values[] = '(?,?,?,?,?)';
                $params[] = $mapId;
                $params[] = $x;
                $params[] = $y;
                $params[] = $type;
                $params[] = $subtype;

                if (count($values) >= $batchSize) {
                    $this->db->exec(
                        'INSERT INTO editor_map_tiles (map_id, x, y, type, subtype) VALUES ' . implode(',', $values),
                        $params
                    );
                    $values = [];
                    $params = [];
                }
            }
        }

        if (!empty($values)) {
            $this->db->exec(
                'INSERT INTO editor_map_tiles (map_id, x, y, type, subtype) VALUES ' . implode(',', $values),
                $params
            );
        }
    }
}

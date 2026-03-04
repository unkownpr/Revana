<?php declare(strict_types=1);

namespace Devana\Controllers;

use Devana\Helpers\DataParser;
use Devana\Helpers\InputSanitizer;
use Devana\Services\BarbarianService;
use Devana\Services\MapEditorService;
use Devana\Services\MapService;
use Devana\Services\UserService;

final class MapController extends Controller
{
    private function getConfigValue(string $name, string $default = ''): string
    {
        $row = $this->db->exec('SELECT value FROM config WHERE name = ? ORDER BY ord ASC LIMIT 1', [$name]);
        return (string) ($row[0]['value'] ?? $default);
    }

    /**
     * @return array<string, string>
     */
    private function getTownTileBiomeSettings(): array
    {
        $rows = $this->db->exec("SELECT name, value FROM config WHERE name LIKE 'town_tile_biome_%'");
        $out = [];
        foreach ($rows as $row) {
            $full = (string) ($row['name'] ?? '');
            $key = substr($full, strlen('town_tile_biome_'));
            if (preg_match('/^\d+_\d+$/', $key)) {
                $out[$key] = (string) ($row['value'] ?? '');
            }
        }
        return $out;
    }

    /**
     * @return array{defaults: array<int, string>, biome: array<int, array<string, string>>}
     */
    private function getTownTileFactionSettings(): array
    {
        $rows = $this->db->exec("SELECT name, value FROM config WHERE name LIKE 'town_tile_faction_%'");
        $out = [
            'defaults' => [],
            'biome' => [],
        ];

        foreach ($rows as $row) {
            $name = (string) ($row['name'] ?? '');
            $value = (string) ($row['value'] ?? '');

            if (preg_match('/^town_tile_faction_(\d+)_default$/', $name, $m)) {
                $factionId = (int) $m[1];
                $out['defaults'][$factionId] = $value;
                continue;
            }

            if (preg_match('/^town_tile_faction_(\d+)_biome_(\d+_\d+)$/', $name, $m)) {
                $factionId = (int) $m[1];
                $biomeKey = (string) $m[2];
                if (!isset($out['biome'][$factionId])) {
                    $out['biome'][$factionId] = [];
                }
                $out['biome'][$factionId][$biomeKey] = $value;
            }
        }

        return $out;
    }

    /**
     * @param array<string, array<string, mixed>> $tileMap
     */
    private function detectBiomeKeyFromTileMap(array $tileMap, int $x, int $y): string
    {
        $counts = [];
        for ($dy = -1; $dy <= 1; $dy++) {
            for ($dx = -1; $dx <= 1; $dx++) {
                if ($dx === 0 && $dy === 0) {
                    continue;
                }
                $key = ($x + $dx) . ',' . ($y + $dy);
                if (!isset($tileMap[$key])) {
                    continue;
                }
                $t = (int) ($tileMap[$key]['type'] ?? -1);
                if ($t === 3 || $t < 0) {
                    continue;
                }
                $s = (int) ($tileMap[$key]['subtype'] ?? 0);
                $bs = $t . '_' . $s;
                $counts[$bs] = ($counts[$bs] ?? 0) + 1;
            }
        }

        if (!empty($counts)) {
            arsort($counts);
            return (string) array_key_first($counts);
        }

        return '1_0';
    }

    private function isValidTownTileImage(string $imgFile): bool
    {
        return (bool) preg_match('/^(env_3[0-9A-Za-z_-]*|tile_3_[0-9A-Za-z_-]+)\.(gif|png|jpe?g|webp)$/i', $imgFile);
    }

    private function legacyTownImageByPopulation(int $population): string
    {
        if ($population <= 100) {
            return 'env_31.gif';
        }
        if ($population <= 200) {
            return 'env_32.gif';
        }
        if ($population <= 300) {
            return 'env_33.gif';
        }
        return 'env_34.gif';
    }

    public function view(\Base $f3): void
    {
        $hasX = $f3->exists('GET.x');
        $hasY = $f3->exists('GET.y');

        if ($hasX && $hasY) {
            $x = max(0, InputSanitizer::cleanInt($f3->get('GET.x')));
            $y = max(0, InputSanitizer::cleanInt($f3->get('GET.y')));
        } else {
            $x = 0;
            $y = 0;
            $user = $this->currentUser();
            if ($user) {
                $firstTownId = $this->townService()->getFirstTownId($user['id']);
                if ($firstTownId !== null) {
                    $mapService = new MapService($this->db);
                    $loc = $mapService->getTownLocation($firstTownId);
                    if ($loc) {
                        $x = (int) $loc['x'];
                        $y = (int) $loc['y'];
                    }
                }
            }
            if ($x === 0 && $y === 0) {
                $mapSize = (int) ($f3->get('game.map_size') ?? 200);
                $x = rand(0, $mapSize);
                $y = rand(0, $mapSize);
            }
        }

        $data = $this->buildMapData($x, $y);
        $data['page_title'] = $f3->get('lang.map') ?? 'Map';

        $this->render('map/view.html', $data);
    }

    public function ajax(\Base $f3): void
    {
        $x = InputSanitizer::cleanInt($f3->get('GET.x'));
        $y = InputSanitizer::cleanInt($f3->get('GET.y'));

        try {
            $data = $this->buildMapData($x, $y);
            header('Content-Type: application/json');
            echo json_encode($data);
        } catch (\Throwable $e) {
            error_log('[map.ajax] ' . $e->getMessage());
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'map_load_failed']);
        }
    }

    public function detail(\Base $f3, array $params = []): void
    {
        $x = InputSanitizer::cleanInt($params['x'] ?? $this->get('x', 0));
        $y = InputSanitizer::cleanInt($params['y'] ?? $this->get('y', 0));

        $mapService = new MapService($this->db);
        $tile = $mapService->getTile($x, $y);

        if ($tile === null) {
            $this->flashAndRedirect('Tile not found.', '/map');
            return;
        }

        $typeNames = [0 => 'Plains', 1 => 'Resource Land', 2 => 'Water', 3 => 'Town'];
        $tType = (int) $tile['type'];

        $tileData = [
            'x' => $x,
            'y' => $y,
            'type' => $tType,
            'subtype' => (int) $tile['subtype'],
            'type_name' => $typeNames[$tType] ?? 'Unknown',
            'bonus' => '',
            'town_id' => 0,
            'town_name' => '',
            'player_id' => 0,
            'player_name' => '',
            'population' => 0,
            'alliance_name' => '',
            'alliance_id' => 0,
            'alliance_tag' => '',
            'can_settle' => ($tType === 1),
        ];

        // Resource land bonus
        if ($tType === 1) {
            $bonusTypes = [1 => 'Crop +25%', 2 => 'Lumber +25%', 3 => 'Stone +25%', 4 => 'Iron +25%'];
            $tileData['bonus'] = $bonusTypes[(int) $tile['subtype']] ?? '';
        }

        // If it's a town tile, get town info
        if ($tType === 3 && (int) $tile['subtype'] > 0) {
            $town = $this->townService()->findById((int) $tile['subtype']);
            if ($town !== null) {
                $tileData['town_id'] = (int) $town['id'];
                $tileData['town_name'] = $town['name'];
                $tileData['population'] = (int) ($town['population'] ?? 0);
                $tileData['can_settle'] = false;

                $ownerResult = $this->db->exec('SELECT id, name, faction, alliance FROM users WHERE id = ?', [(int) $town['owner']]);
                $owner = $ownerResult[0] ?? null;
                if ($owner) {
                    $tileData['player_id'] = (int) $owner['id'];
                    $tileData['player_name'] = $owner['name'];
                    if ((int) ($owner['alliance'] ?? 0) > 0) {
                        $allianceResult = $this->db->exec('SELECT id, name, tag FROM alliances WHERE id = ?', [(int) $owner['alliance']]);
                        if (!empty($allianceResult)) {
                            $tileData['alliance_id'] = (int) $allianceResult[0]['id'];
                            $tileData['alliance_name'] = $allianceResult[0]['name'];
                            $tileData['alliance_tag'] = $allianceResult[0]['tag'] ?? '';
                        }
                    }
                }
            }
        }

        $user = $this->currentUser();
        $currentTownId = 0;
        if ($user) {
            $currentTownId = $this->townService()->getFirstTownId($user['id']) ?? 0;
        }

        $this->render('map/detail.html', [
            'page_title' => "Map ({$x},{$y})",
            'tile' => $tileData,
            'current_town_id' => $currentTownId,
        ]);
    }

    public function acquire(\Base $f3): void
    {
        if (!$this->requireCsrf('/map')) return;

        $user = $this->requireAuth();
        if ($user === null) return;

        $maxTowns = max(1, (int) ($f3->get('game.max_towns') ?? 3));
        $ownedTowns = (int) (($this->db->exec(
            'SELECT COUNT(*) AS cnt FROM towns WHERE owner = ?',
            [$user['id']]
        )[0]['cnt'] ?? 0));
        if ($ownedTowns >= $maxTowns) {
            $this->flashAndRedirect('You have reached the maximum number of towns.', '/towns');
            return;
        }

        $townId = InputSanitizer::cleanInt($this->post('town_id', 0));

        $town = $this->townService()->findById($townId);

        if ($town === null) {
            $this->flashAndRedirect('Town not found.', '/map');
            return;
        }

        // Must be abandoned (owner = 0)
        if ((int) $town['owner'] !== 0) {
            $this->flashAndRedirect('This town is not abandoned.', '/map');
            return;
        }

        // Transfer ownership
        $this->db->exec(
            'UPDATE towns SET owner = ?, isCapital = 0 WHERE id = ?',
            [$user['id'], $townId]
        );
        (new UserService($this->db))->recalculatePoints((int) $user['id']);

        $this->flashAndRedirect('Town acquired!', '/town/' . $townId);
    }

    public function settle(\Base $f3, array $params = []): void
    {
        if (!$this->requireCsrf('/map')) return;

        $user = $this->requireAuth();
        if ($user === null) return;

        $maxTowns = max(1, (int) ($f3->get('game.max_towns') ?? 3));
        $ownedTowns = (int) (($this->db->exec(
            'SELECT COUNT(*) AS cnt FROM towns WHERE owner = ?',
            [$user['id']]
        )[0]['cnt'] ?? 0));
        if ($ownedTowns >= $maxTowns) {
            $this->flashAndRedirect('You have reached the maximum number of towns.', '/towns');
            return;
        }

        $x = InputSanitizer::cleanInt($params['x'] ?? $this->post('x', 0));
        $y = InputSanitizer::cleanInt($params['y'] ?? $this->post('y', 0));
        $townName = InputSanitizer::clean($this->post('name', 'New Town'));

        if (empty($townName)) {
            $townName = 'New Town';
        }

        // Check tile is land (type=1), not already a town
        $mapService = new MapService($this->db);
        $tile = $mapService->getTile($x, $y);

        if ($tile === null || (int) $tile['type'] !== 1) {
            $this->flashAndRedirect('You can only settle on resource land.', '/map/' . $x . '/' . $y);
            return;
        }

        // Check capital requirements
        $capital = $this->townService()->getCapital($user['id']);

        if ($capital === null) {
            $this->flashAndRedirect('You need a capital first.', '/towns');
            return;
        }

        $capitalBuildings = DataParser::toIntArray($capital['buildings']);
        $capitalArmy = DataParser::toIntArray($capital['army']);

        // Main building (index 7) must be level 10+
        if (($capitalBuildings[7] ?? 0) < 10) {
            $this->flashAndRedirect('Your capital main building must be level 10.', '/town/' . $capital['id']);
            return;
        }

        // Must have 10+ colonists (army index 11)
        if (($capitalArmy[11] ?? 0) < 10) {
            $this->flashAndRedirect('You need at least 10 colonists in your capital.', '/town/' . $capital['id']);
            return;
        }

        // Deduct 10 colonists from capital
        $capitalArmy[11] -= 10;
        $this->db->exec(
            'UPDATE towns SET army = ?, upkeep = ? WHERE id = ?',
            [DataParser::serialize($capitalArmy), array_sum($capitalArmy), (int) $capital['id']]
        );

        // Create new town
        $newTownId = $this->townService()->createTown($user['id'], $townName);

        // Place on map
        $mapService->placeTown($newTownId, $x, $y);
        (new UserService($this->db))->recalculatePoints((int) $user['id']);

        $this->flashAndRedirect('New town founded!', '/town/' . $newTownId);
    }

    public function suggest(\Base $f3): void
    {
        $mapService = new MapService($this->db);
        $tile = $mapService->findAvailableLandTile();

        if ($tile === null) {
            $this->flashAndRedirect('No available land tiles found.', '/map');
            return;
        }

        $this->redirect('/map/' . $tile['x'] . '/' . $tile['y']);
    }

    /**
     * Build map data array for a given center coordinate.
     *
     * @return array<string, mixed>
     */
    private function buildMapData(int $x, int $y): array
    {
        // Clamp coordinates to actual map boundaries
        $bounds = $this->db->exec('SELECT MIN(x) AS minx, MAX(x) AS maxx, MIN(y) AS miny, MAX(y) AS maxy FROM map');
        if (!empty($bounds) && $bounds[0]['maxx'] !== null) {
            $x = max((int) $bounds[0]['minx'], min((int) $bounds[0]['maxx'], $x));
            $y = max((int) $bounds[0]['miny'], min((int) $bounds[0]['maxy'], $y));
        }

        $mapService = new MapService($this->db);
        $tiles = $mapService->getRange($x, $y, 3);

        $tileMap = [];
        foreach ($tiles as $tile) {
            $key = $tile['x'] . ',' . $tile['y'];
            $tileMap[$key] = $tile;
        }

        $user = $this->currentUser();
        $myTownId = 0;
        $myUserId = $user ? (int) $user['id'] : 0;
        if ($user) {
            $myTownId = $this->townService()->getFirstTownId($user['id']) ?? 0;
        }

        $townSubtypes = [];
        foreach ($tiles as $tile) {
            if ((int) $tile['type'] === 3 && (int) $tile['subtype'] > 0) {
                $townSubtypes[] = (int) $tile['subtype'];
            }
        }

        $townData = [];
        $allianceData = [];
        if (!empty($townSubtypes)) {
            $placeholders = implode(',', array_fill(0, count($townSubtypes), '?'));
            $rows = $this->db->exec(
                "SELECT t.id, t.name, t.owner, t.population, u.id AS uid, u.name AS player_name, u.alliance, u.faction AS player_faction
                 FROM towns t LEFT JOIN users u ON t.owner = u.id
                 WHERE t.id IN ({$placeholders})",
                $townSubtypes
            );
            foreach ($rows as $r) {
                $townData[(int) $r['id']] = $r;
            }

            $allianceIds = [];
            foreach ($rows as $r) {
                $aid = (int) ($r['alliance'] ?? 0);
                if ($aid > 0) {
                    $allianceIds[$aid] = $aid;
                }
            }
            if (!empty($allianceIds)) {
                $aPlaceholders = implode(',', array_fill(0, count($allianceIds), '?'));
                $aRows = $this->db->exec(
                    "SELECT id, name FROM alliances WHERE id IN ({$aPlaceholders})",
                    array_values($allianceIds)
                );
                foreach ($aRows as $a) {
                    $allianceData[(int) $a['id']] = $a['name'];
                }
            }
        }

        $imgs = '/default/';
        if ($user) {
            $rawImgs = $user['imgs'] ?? 'default/';
            $imgs = '/' . ltrim($rawImgs, '/');
        }

        // Load brush maps for custom tile images.
        // If map editor schema is incomplete, degrade gracefully instead of throwing 500.
        $brushImageMap = [];
        $brushNameMap = [];
        try {
            $editorService = new MapEditorService($this->db);
            $editorService->ensureTileBrushesTable();
            $brushImageMap = $editorService->getBrushImageMap();

            $this->loadLanguage();
            $lang = $this->f3->get('lang');
            $langCode = strtolower($this->f3->get('current_lang_code'));
            $brushNameMap = $editorService->getTranslatedBrushNameMap($lang, $langCode);
        } catch (\Throwable $e) {
            error_log('[map.brushes] ' . $e->getMessage());
        }

        $townTileDefault = basename($this->getConfigValue('town_tile_default', ''));
        $townTileByBiome = $this->getTownTileBiomeSettings();
        $townTileByFaction = $this->getTownTileFactionSettings();

        $mapTiles = [];
        for ($k = 3; $k >= -3; $k--) {
            for ($j = -3; $j <= 3; $j++) {
                $tileX = $x + $j;
                $tileY = $y + $k;
                $st_x = ($k + 3) * 40 + ($j + 3) * 40;
                $st_y = (3 - $k) * 20 + ($j + 3) * 20;

                $key = $tileX . ',' . $tileY;
                $tile = $tileMap[$key] ?? null;

                $imgFile = 'env_x.gif';
                $descName = 'void';
                $descPlayer = '-';
                $descPop = '-';
                $descAlliance = '-';
                $href = '/map?x=' . $tileX . '&y=' . $tileY;

                if ($tile) {
                    $type = (int) $tile['type'];
                    $subtype = (int) $tile['subtype'];

                    if ($type === 3 && $subtype > 0) {
                        // Town tiles: biome-aware image rule, fallback to legacy population tiers.
                        $td = $townData[$subtype] ?? null;
                        if ($td) {
                            $pop = (int) $td['population'];
                            $biomeKey = $this->detectBiomeKeyFromTileMap($tileMap, $tileX, $tileY);
                            $factionId = (int) ($td['player_faction'] ?? 0);

                            // Priority: faction+biome > faction default > biome > global default > legacy(population)
                            $configured = '';
                            if ($factionId > 0) {
                                $configured = basename((string) ($townTileByFaction['biome'][$factionId][$biomeKey] ?? ''));
                                if ($configured === '' || !$this->isValidTownTileImage($configured)) {
                                    $configured = basename((string) ($townTileByFaction['defaults'][$factionId] ?? ''));
                                }
                            }
                            if ($configured === '' || !$this->isValidTownTileImage($configured)) {
                                $configured = basename((string) ($townTileByBiome[$biomeKey] ?? ''));
                            }
                            if ($configured === '' || !$this->isValidTownTileImage($configured)) {
                                $configured = $townTileDefault;
                            }
                            if ($configured !== '' && $this->isValidTownTileImage($configured)) {
                                $imgFile = $configured;
                            } else {
                                $imgFile = $this->legacyTownImageByPopulation($pop);
                            }

                            $descName = str_replace("'", "\\'", $td['name']);
                            $descPop = (string) $pop;

                            if ((int) $td['owner'] > 0) {
                                $descPlayer = str_replace("'", "\\'", $td['player_name'] ?? '');
                                $allianceId = (int) ($td['alliance'] ?? 0);
                                $descAlliance = str_replace("'", "\\'", $allianceData[$allianceId] ?? '');

                                $uid = (int) $td['uid'];
                                $tid = (int) $td['id'];
                                $href = "javascript:xmenu({$uid},{$tid},{$myTownId},{$tileX},{$tileY})";
                            } else {
                                $descPlayer = '[abandoned]';
                                $href = '/map/' . $tileX . '/' . $tileY;
                            }
                        }
                    } else {
                        // Non-town tiles: use brush map, fallback to random variants
                        $imgKey = $type . '_' . $subtype;
                        if (isset($brushImageMap[$imgKey])) {
                            $imgFile = $brushImageMap[$imgKey];
                            $descName = $brushNameMap[$imgKey] ?? 'Land';
                        } elseif ($type === 0) {
                            $imgFile = 'env_0' . rand(1, 4) . '.gif';
                            $descName = 'Water';
                        } elseif ($type === 1) {
                            $imgFile = 'env_1' . ($subtype > 0 ? $subtype : rand(1, 6)) . '.gif';
                            $descName = 'Land';
                        } elseif ($type === 2) {
                            $imgFile = 'env_2' . ($subtype > 0 ? $subtype : rand(1, 4)) . '.gif';
                            $descName = 'Mountains';
                        }
                    }
                }

                $coords = ($st_x + 40) . ',' . $st_y . ','
                    . ($st_x + 80) . ',' . ($st_y + 20) . ','
                    . ($st_x + 40) . ',' . ($st_y + 40) . ','
                    . $st_x . ',' . ($st_y + 20);

                $isOwnTown = false;
                $ownTownName = '';
                if ($myUserId > 0 && isset($tile) && (int) ($tile['type'] ?? -1) === 3) {
                    $subtype = (int) ($tile['subtype'] ?? 0);
                    if ($subtype > 0 && isset($townData[$subtype])) {
                        $td2 = $townData[$subtype];
                        if ((int) ($td2['uid'] ?? 0) === $myUserId) {
                            $isOwnTown = true;
                            $ownTownName = $td2['name'] ?? '';
                        }
                    }
                }

                $mapTiles[] = [
                    'st_x' => $st_x,
                    'st_y' => $st_y,
                    'img' => $imgs . 'map/' . $imgFile,
                    'coords' => $coords,
                    'href' => $href,
                    'desc_name' => $descName,
                    'desc_player' => $descPlayer,
                    'desc_pop' => $descPop,
                    'desc_alliance' => $descAlliance,
                    'is_own' => $isOwnTown,
                    'own_name' => $ownTownName,
                ];
            }
        }

        $yLabels = [];
        for ($k = 3; $k >= -3; $k--) {
            $yLabels[] = [
                'label' => $y + $k,
                'st_x' => ($k + 3) * 40,
                'st_y' => (3 - $k) * 20,
            ];
        }

        $xLabels = [];
        for ($j = -3; $j <= 3; $j++) {
            $xLabels[] = [
                'label' => $x + $j,
                'st_x' => ($j + 3) * 40,
                'st_y' => 160 + ($j + 3) * 20,
            ];
        }

        $nav = [
            'north_x' => $x,
            'north_y' => $y + 1,
            'south_x' => $x,
            'south_y' => $y - 1,
            'east_x' => $x + 1,
            'east_y' => $y,
            'west_x' => $x - 1,
            'west_y' => $y,
        ];

        // Barbarian camps visible in the current viewport.
        $rawCamps = [];
        try {
            $barbarianService = new BarbarianService($this->db);
            $rawCamps = $barbarianService->getCampsForMap($x, $y, 3);
        } catch (\Throwable $e) {
            error_log('[map.camps] ' . $e->getMessage());
        }

        $campOverlays = [];
        foreach ($rawCamps as $camp) {
            $cj = (int) $camp['x'] - $x; // dx
            $ck = (int) $camp['y'] - $y; // dy
            // Pixel position (same formula as tile loop, centred on tile diamond top)
            $cst_x = ($ck + 3) * 40 + ($cj + 3) * 40;
            $cst_y = (3 - $ck) * 20 + ($cj + 3) * 20;
            $campOverlays[] = [
                'id'    => (int) $camp['id'],
                'x'     => (int) $camp['x'],
                'y'     => (int) $camp['y'],
                'level' => (int) $camp['level'],
                'st_x'  => $cst_x + 28,
                'st_y'  => $cst_y - 4,
            ];
        }

        $clock = $this->getServerClock();

        return [
            'center_x'      => $x,
            'center_y'      => $y,
            'mapTiles'      => $mapTiles,
            'yLabels'       => $yLabels,
            'xLabels'       => $xLabels,
            'nav'           => $nav,
            'my_town_id'    => $myTownId,
            'my_user_id'    => $myUserId,
            'imgs'          => $imgs,
            'camp_overlays' => $campOverlays,
            'server_hour'   => $clock['hour'],
            'server_minute' => $clock['minute'],
        ];
    }
}

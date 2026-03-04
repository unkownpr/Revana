<?php declare(strict_types=1);

namespace Devana\Controllers;

use Devana\Helpers\DataParser;
use Devana\Helpers\InputSanitizer;
use Devana\Services\ArmyService;
use Devana\Services\ResourceService;
use Devana\Services\QueueService;
use Devana\Services\BuildingService;
use Devana\Services\UserService;
use Devana\Services\MissionService;
use Devana\Services\WeeklyMissionService;
use Devana\Services\TradeService;
use Devana\Services\MapService;
use Devana\Services\PremiumStoreService;

final class TownController extends Controller
{
    public function listTowns(\Base $f3): void
    {
        $user = $this->requireAuth();
        if ($user === null) return;

        $towns = $this->townService()->findByOwner($user['id']);

        $this->render('town/list.html', [
            'page_title' => $f3->get('lang.towns') ?? 'Towns',
            'towns' => $towns,
        ]);
    }

    public function view(\Base $f3, array $params): void
    {
        $townId = (int) ($params['id'] ?? 0);
        $user = $this->requireAuth();
        if ($user === null) return;

        $town = $this->requireOwnedTown($townId);
        if ($town === null) return;

        // Load language early for building name translations
        $this->loadLanguage();
        $lang = $this->f3->get('lang');
        $faction = $user['faction'];

        // Process all queues
        $queueService = new QueueService($this->db);
        $resourceService = new ResourceService($this->db);

        $resourceService->updateResources($townId);
        $queueService->processConstructionQueue($townId, $user['faction']);
        $queueService->processWeaponQueue($townId);
        $queueService->processUnitQueue($townId);
        $queueService->processUpgradeQueue($townId);
        $queueService->processTradeQueue($townId);
        $queueService->processArmyQueue($townId);

        // Reload town after processing
        $townService = $this->townService();
        $town = $townService->findById($townId);

        // Get building data for this faction
        $buildingService = new BuildingService($this->db);
        $buildings = $buildingService->getByFaction($user['faction']);

        // Parse town data
        $buildingLevels = $townService->parseBuildingLevels($town['buildings']);
        $resources = $townService->parseResources($town['resources']);
        $limits = $townService->parseLimits($town['limits']);
        $production = $townService->parseProduction($town['production']);
        $land = $townService->parseLand($town['land']);

        // Get queues for display
        $rawQueue = $queueService->getConstructionQueue($townId);
        $armyMovements = $queueService->getArmyMovements($townId);
        $rawUnitQueue = $queueService->getUnitQueue($townId);
        $rawWeaponQueue = $queueService->getWeaponQueue($townId);

        // Enrich unit queue with names (prefer language file translations)
        $units = $this->db->exec('SELECT type, name FROM units WHERE faction = ? ORDER BY type', [$user['faction']]);
        $unitNames = [];
        foreach ($units as $u) {
            $uType = (int) $u['type'];
            $unitNames[$uType] = $this->langCatalogText($lang, 'units', (int) $faction, $uType, 0, (string) $u['name']);
        }
        $unitQueue = [];
        foreach ($rawUnitQueue as $uq) {
            $unitQueue[] = [
                'type' => (int) $uq['type'],
                'name' => $unitNames[(int) $uq['type']] ?? 'Unit ' . $uq['type'],
                'quantity' => $uq['quantity'],
                'timeLeft' => $uq['timeLeft'],
            ];
        }

        // Enrich weapon queue with names (prefer language file translations)
        $weapons = $this->db->exec('SELECT type, name FROM weapons WHERE faction = ? ORDER BY type', [$user['faction']]);
        $weaponNames = [];
        foreach ($weapons as $w) {
            $wType = (int) $w['type'];
            $weaponNames[$wType] = $this->langCatalogText($lang, 'weapons', (int) $faction, $wType, 0, (string) $w['name']);
        }
        $weaponQueue = [];
        foreach ($rawWeaponQueue as $wq) {
            $weaponQueue[] = [
                'type' => (int) $wq['type'],
                'name' => $weaponNames[(int) $wq['type']] ?? 'Weapon ' . $wq['type'],
                'quantity' => $wq['quantity'],
                'timeLeft' => $wq['timeLeft'],
            ];
        }

        // Enrich construction queue with correct names and levels
        $constructionQueue = [];
        foreach ($rawQueue as $item) {
            $b = $item['b'];
            $subB = $item['subB'];
            // Use translated name from language file if available
            $bName = $this->langCatalogText($lang, 'buildings', (int) $faction, (int) $b, 0, (string) ($buildings[$b]['name'] ?? ('Building ' . $b)));
            $nameParts = explode('-', $bName);

            if ($subB > -1) {
                // Land building: use second part of name, level from land data
                $name = $nameParts[1] ?? $nameParts[0];
                $level = ($land[$b][$subB] ?? 0) + 1;
            } else {
                // Main building: use first part of name, level from building data
                $name = $nameParts[0];
                $level = $buildingLevels[$b] + 1;
            }

            $constructionQueue[] = [
                'b' => $b,
                'subB' => $subB,
                'name' => $name,
                'level' => $level,
                'timeLeft' => $item['timeLeft'],
            ];
        }

        // Add town coordinates for map link
        $coords = $townService->getTownCoords($townId);
        $town['x'] = $coords['x'] ?? 0;
        $town['y'] = $coords['y'] ?? 0;

        // Net crop production
        $cropNet = ResourceService::netCropProduction(
            $production['crop'],
            (int) $town['population'],
            (int) $town['upkeep']
        );

        // Build building names array for tooltips (matching legacy behavior)
        $levelLabel = $lang['level'] ?? 'Level';
        // Buildings 9, 16, 17 don't show level in tooltip
        $noLevelBuildings = [9, 16, 17];
        $resolveBuildingName = static function (int $type, array $lang, int $faction, string $dbName = ''): string {
            $name = '';
            if (isset($lang['buildings'][$faction][$type][0])) {
                $name = (string) $lang['buildings'][$faction][$type][0];
            } elseif (isset($lang['buildings'][$faction - 1][$type][0])) {
                $name = (string) $lang['buildings'][$faction - 1][$type][0];
            } elseif ($dbName !== '') {
                $name = $dbName;
            }

            if (mb_strlen(trim($name)) <= 1 && isset($lang['buildings']) && is_array($lang['buildings'])) {
                foreach ($lang['buildings'] as $fRows) {
                    if (isset($fRows[$type][0])) {
                        $candidate = (string) $fRows[$type][0];
                        if (mb_strlen(trim($candidate)) > 1) {
                            $name = $candidate;
                            break;
                        }
                    }
                }
            }

            if (mb_strlen(trim($name)) <= 1) {
                $fallback = [
                    0 => 'Crop Building',
                    1 => 'Lumber Building',
                    2 => 'Stone Building',
                    3 => 'Iron Building',
                ];
                $name = $fallback[$type] ?? ('Building ' . $type);
            }

            return $name;
        };
        $buildingNames = [];
        foreach ($buildings as $b) {
            $type = (int) $b['type'];
            // Use translated name from language file if available
            $name = $resolveBuildingName($type, $lang, (int) $faction, (string) ($b['name'] ?? ''));
            $level = $buildingLevels[$type] ?? 0;
            $parts = explode('-', $name);

            if ($type <= 3) {
                // Resource buildings: first part of name, no level
                $buildingNames[$type] = $parts[0];
            } elseif ($type === 7) {
                // Hall/Castle: first part at levels 1-9, second part at level 10
                $partIdx = ($level >= 10) ? 1 : 0;
                $tooltip = $parts[$partIdx] ?? $parts[0];
                if ($level > 0) {
                    $tooltip .= ' ' . $levelLabel . ' ' . $level;
                }
                $buildingNames[$type] = $tooltip;
            } elseif (in_array($type, $noLevelBuildings, true)) {
                // These buildings don't show level
                $buildingNames[$type] = $parts[0];
            } else {
                // All other buildings: first part of name + level
                $tooltip = $parts[0];
                if ($level > 0) {
                    $tooltip .= ' ' . $levelLabel . ' ' . $level;
                }
                $buildingNames[$type] = $tooltip;
            }
        }

        // Parse layout for building positions and background
        $layout = $townService->parseLayout($town['layout'] ?? null);
        $bgDir = $f3->get('ROOT') . '/default/1/';
        $availableBackgrounds = $this->getAllowedTownBackgrounds($town, $bgDir, (string) ($layout['background'] ?? ''));
        $backgroundMasks = $this->getBackgroundMasksForBackgrounds($availableBackgrounds);
        $backgroundPremiumCatalog = $this->getTownBackgroundPremiumCatalog($availableBackgrounds);
        $ownedBackgroundProductIds = $this->getOwnedTownBackgroundProductIds((int) $user['id']);
        $backgroundPremiumMeta = [];
        foreach ($availableBackgrounds as $bg) {
            $meta = $backgroundPremiumCatalog[$bg] ?? null;
            $productId = (int) ($meta['product_id'] ?? 0);
            $isPremium = $meta !== null && $productId > 0;
            $owned = !$isPremium || in_array($productId, $ownedBackgroundProductIds, true);
            $backgroundPremiumMeta[$bg] = [
                'is_premium' => $isPremium ? 1 : 0,
                'product_id' => $productId,
                'price_credits' => max(0, (int) ($meta['price_credits'] ?? 0)),
                'owned' => $owned ? 1 : 0,
            ];
        }

        // Apply biome/default background only when user has not explicitly chosen one in layout
        if (!$this->layoutHasExplicitBackground($town['layout'] ?? null)) {
            $biomeKey = $this->detectTownBiomeKey($town['x'] ?? 0, $town['y'] ?? 0);
            $defaultBg = $this->getConfigValue('town_background_default', 'back.png');
            if (!in_array($defaultBg, $availableBackgrounds, true)) {
                $defaultBg = $availableBackgrounds[0] ?? 'back.png';
            }
            $biomeBg = $this->getConfigValue('town_background_biome_' . $biomeKey, '');
            $resolved = in_array($biomeBg, $availableBackgrounds, true) ? $biomeBg : $defaultBg;
            $layout['background'] = $resolved;
        }

        // Build dispatch army data for inline dispatch panel
        $armyService   = new ArmyService($this->db);
        $dispatchUnits = $armyService->getUnitsByFaction($user['faction']);
        $armyRaw       = $townService->parseArmy($town['army']);
        $dispatchArmy  = [];
        foreach ($armyRaw as $i => $count) {
            if (isset($dispatchUnits[$i])) {
                $unitName = $this->langCatalogText($lang, 'units', (int) $faction, (int) $i, 0, (string) $dispatchUnits[$i]['name']);
                $dispatchArmy[] = ['id' => $i, 'name' => $unitName, 'count' => $count];
            }
        }

        // Stats panel: resource fill percentages
        $rFill = static function (float $v, float $lim): int {
            return $lim > 0 ? min(100, (int) floor($v / $lim * 100)) : 0;
        };
        $resourceFill = [
            'crop'   => $rFill((float)($resources['crop']   ?? 0), (float) max(1, $limits['crop']      ?? 1)),
            'lumber' => $rFill((float)($resources['lumber'] ?? 0), (float) max(1, $limits['resources'] ?? 1)),
            'stone'  => $rFill((float)($resources['stone']  ?? 0), (float) max(1, $limits['resources'] ?? 1)),
            'iron'   => $rFill((float)($resources['iron']   ?? 0), (float) max(1, $limits['resources'] ?? 1)),
            'gold'   => $rFill((float)($resources['gold']   ?? 0), (float) max(1, $limits['gold']      ?? 1)),
        ];
        $prodVals = [
            max(0, (int) $cropNet),
            max(0, (int) ($production['lumber'] ?? 0)),
            max(0, (int) ($production['stone']  ?? 0)),
            max(0, (int) ($production['iron']   ?? 0)),
            max(0, (int) ($production['gold']   ?? 0)),
        ];
        $prodMax = max(1, ...$prodVals);
        $productionFill = [
            'crop'   => $rFill((float) $prodVals[0], (float) $prodMax),
            'lumber' => $rFill((float) $prodVals[1], (float) $prodMax),
            'stone'  => $rFill((float) $prodVals[2], (float) $prodMax),
            'iron'   => $rFill((float) $prodVals[3], (float) $prodMax),
            'gold'   => $rFill((float) $prodVals[4], (float) $prodMax),
        ];
        // Stats panel: army (non-zero units)
        $statsArmy = [];
        foreach ($armyRaw as $i => $count) {
            if ($count > 0 && isset($dispatchUnits[$i])) {
                $unitName = $this->langCatalogText($lang, 'units', (int) $faction, (int) $i, 0, (string) $dispatchUnits[$i]['name']);
                $statsArmy[] = ['name' => $unitName, 'count' => $count];
            }
        }

        // Market panel data
        $tradeService    = new TradeService($this->db);
        $marketLevel     = $buildingLevels[10] ?? 0;
        $merchantsTotal  = max(1, $marketLevel);
        $merchantsAvail  = $tradeService->getAvailableMerchants($townId, $marketLevel);
        $resLangKeys     = [0 => 'crop', 1 => 'lumber', 2 => 'stone', 3 => 'iron', 4 => 'gold'];
        $rawMarketOffers = $tradeService->getOffersBySeller($townId);
        $marketOffers    = [];
        foreach ($rawMarketOffers as $offer) {
            $sType = (int) ($offer['sType'] ?? 0);
            $bType = (int) ($offer['bType'] ?? 0);
            $sKey  = $resLangKeys[$sType] ?? 'crop';
            $bKey  = $resLangKeys[$bType] ?? 'crop';
            $marketOffers[] = [
                'sell' => (int) ($offer['sQ'] ?? 0) . ' ' . ($lang[$sKey] ?? ucfirst($sKey)),
                'buy'  => (int) ($offer['bQ'] ?? 0) . ' ' . ($lang[$bKey] ?? ucfirst($bKey)),
            ];
        }

        // Map mini-tiles for map toast panel
        $mapService = new MapService($this->db);
        $userImgs   = '/' . ltrim((string) ($user['imgs'] ?? 'default/'), '/');
        $miniTiles  = [];
        if (!empty($coords) && isset($coords['x']) && isset($coords['y'])) {
            $miniTiles = $mapService->buildMiniMap((int) $coords['x'], (int) $coords['y'], $userImgs);
        }

        // Load missions for inline toast panel
        $missionService = new MissionService($this->db);
        $rawMissions = $missionService->getDailyMissions((int) $user['id']);
        $langFile = strtolower((string) ($user['language'] ?? ($user['lang'] ?? 'en.php')));
        $isTr = str_starts_with($langFile, 'tr');
        $missionLabels = [
            MissionService::TYPE_BUILD => $lang['missionBuild'] ?? 'Complete {n} constructions',
            MissionService::TYPE_TRAIN => $lang['missionTrain'] ?? 'Train {n} units',
            MissionService::TYPE_RAID  => $lang['missionRaid']  ?? 'Send {n} raids',
            MissionService::TYPE_TRADE => $lang['missionTrade'] ?? 'Make {n} trades',
            MissionService::TYPE_GOLD  => $lang['missionGold']  ?? 'Collect {n} gold',
        ];
        $townMissions = [];
        foreach ($rawMissions as $m) {
            $type     = (int) $m['type'];
            $target   = (int) $m['target'];
            $progress = (int) $m['progress'];
            $pct      = $target > 0 ? min(100, (int) round($progress / $target * 100)) : 100;
            $baseLabel = $missionLabels[$type] ?? 'Mission {n}';
            $customTpl = $isTr
                ? (string) ($m['template_title_tr'] ?? '')
                : (string) ($m['template_title_en'] ?? '');
            if (trim($customTpl) !== '') {
                $baseLabel = $customTpl;
            }
            $label = str_replace('{n}', (string) $target, $baseLabel);
            $townMissions[] = [
                'id'       => (int) $m['id'],
                'label'    => $label,
                'progress' => $progress,
                'target'   => $target,
                'pct'      => $pct,
                'complete' => $progress >= $target,
                'claimed'  => (int) $m['claimed'] === 1,
            ];
        }

        // Load weekly missions for inline toast panel
        $weeklyService = new WeeklyMissionService($this->db);
        $rawWeekly = $weeklyService->getWeeklyMissions((int) $user['id']);
        $weeklyLabels = [
            WeeklyMissionService::TYPE_BUILD  => $lang['weeklyMissionBuild']  ?? 'Complete {n} constructions this week',
            WeeklyMissionService::TYPE_TRAIN  => $lang['weeklyMissionTrain']  ?? 'Train {n} units this week',
            WeeklyMissionService::TYPE_RAID   => $lang['weeklyMissionRaid']   ?? 'Win {n} raids this week',
            WeeklyMissionService::TYPE_TRADE  => $lang['weeklyMissionTrade']  ?? 'Make {n} trades this week',
            WeeklyMissionService::TYPE_GOLD   => $lang['weeklyMissionGold']   ?? 'Collect {n} gold this week',
        ];
        $townWeeklyMissions = [];
        foreach ($rawWeekly as $wm) {
            $type     = (int) $wm['type'];
            $target   = (int) $wm['target'];
            $progress = (int) $wm['progress'];
            $pct      = $target > 0 ? min(100, (int) round($progress / $target * 100)) : 100;
            $label    = str_replace('{n}', (string) $target, $weeklyLabels[$type] ?? 'Weekly Mission');
            $townWeeklyMissions[] = [
                'label'    => $label,
                'progress' => $progress,
                'target'   => $target,
                'pct'      => $pct,
                'complete' => $progress >= $target,
                'claimed'  => (int) ($wm['claimed'] ?? 0) === 1,
            ];
        }

        $clock = $this->getServerClock();

        $this->render('town/view.html', [
            'page_title' => $f3->get('lang.town') ?? 'Town',
            'town' => $town,
            'building_levels' => $buildingLevels,
            'resources' => $resources,
            'limits' => $limits,
            'production' => $production,
            'crop_net' => $cropNet,
            'land' => $land,
            'buildings' => $buildings,
            'building_names' => $buildingNames,
            'construction_queue' => $constructionQueue,
            'army_movements' => $armyMovements,
            'unit_queue' => $unitQueue,
            'weapon_queue' => $weaponQueue,
            'view_mode' => $this->get('v', ''),
            'hide_resource_bar' => true,
            'layout' => $layout,
            'available_backgrounds' => $availableBackgrounds,
            'background_masks' => $backgroundMasks,
            'background_premium_meta' => $backgroundPremiumMeta,
            'town_missions'         => $townMissions,
            'town_weekly_missions'  => $townWeeklyMissions,
            'dispatch_army'    => $dispatchArmy,
            'resource_fill'    => $resourceFill,
            'production_fill'  => $productionFill,
            'stats_army'       => $statsArmy,
            'market_level'     => $marketLevel,
            'merchants_avail'  => $merchantsAvail,
            'merchants_total'  => $merchantsTotal,
            'market_offers'    => $marketOffers,
            'mini_tiles'       => $miniTiles,
            'server_hour'      => $clock['hour'],
            'server_minute'    => $clock['minute'],
        ]);
    }

    public function stats(\Base $f3, array $params): void
    {
        $townId = (int) ($params['id'] ?? 0);
        $user = $this->requireAuth();
        if ($user === null) return;

        $town = $this->requireOwnedTown($townId);
        if ($town === null) return;

        // Load language early for translations
        $this->loadLanguage();
        $lang = $this->f3->get('lang');
        $faction = $user['faction'];

        $townService = $this->townService();
        $buildingService = new BuildingService($this->db);
        $buildings = $buildingService->getByFaction($user['faction']);
        $buildingLevels = $townService->parseBuildingLevels($town['buildings']);
        $resources = $townService->parseResources($town['resources']);
        $limits = $townService->parseLimits($town['limits']);
        $production = $townService->parseProduction($town['production']);

        // Add coordinates from map
        $coords = $townService->getTownCoords($townId);
        $town['x'] = $coords['x'] ?? 0;
        $town['y'] = $coords['y'] ?? 0;

        // Add level to each building (with translated names)
        foreach ($buildings as &$b) {
            $bType = (int) $b['type'];
            $b['level'] = $buildingLevels[$bType] ?? 0;
            $b['name'] = $this->langCatalogText($lang, 'buildings', (int) $faction, $bType, 0, (string) $b['name']);
        }
        unset($b);

        // Build army with unit names (translated)
        $armyRaw = $townService->parseArmy($town['army']);
        $units = $this->db->exec('SELECT * FROM units WHERE faction = ? ORDER BY type', [$user['faction']]);
        $army = [];
        foreach ($armyRaw as $i => $count) {
            if ($count > 0 && isset($units[$i])) {
                $unitName = $this->langCatalogText($lang, 'units', (int) $faction, (int) $i, 0, (string) $units[$i]['name']);
                $army[] = ['name' => $unitName, 'count' => $count];
            }
        }

        $cropNet = ResourceService::netCropProduction(
            $production['crop'],
            (int) $town['population'],
            (int) $town['upkeep']
        );

        $ratio = static function (float $value, float $limit): int {
            if ($limit <= 0) {
                return 0;
            }
            $percent = (int) floor(($value / $limit) * 100);
            return max(0, min(100, $percent));
        };

        $resourceFill = [
            'crop' => $ratio((float) ($resources['crop'] ?? 0), (float) ($limits['crop'] ?? 0)),
            'lumber' => $ratio((float) ($resources['lumber'] ?? 0), (float) ($limits['resources'] ?? 0)),
            'stone' => $ratio((float) ($resources['stone'] ?? 0), (float) ($limits['resources'] ?? 0)),
            'iron' => $ratio((float) ($resources['iron'] ?? 0), (float) ($limits['resources'] ?? 0)),
            'gold' => $ratio((float) ($resources['gold'] ?? 0), (float) ($limits['gold'] ?? 0)),
        ];

        $prodValues = [
            max(0, (int) $cropNet),
            max(0, (int) ($production['lumber'] ?? 0)),
            max(0, (int) ($production['stone'] ?? 0)),
            max(0, (int) ($production['iron'] ?? 0)),
            max(0, (int) ($production['gold'] ?? 0)),
        ];
        $prodMax = max(1, ...$prodValues);
        $productionFill = [
            'crop' => $ratio((float) max(0, (int) $cropNet), (float) $prodMax),
            'lumber' => $ratio((float) max(0, (int) ($production['lumber'] ?? 0)), (float) $prodMax),
            'stone' => $ratio((float) max(0, (int) ($production['stone'] ?? 0)), (float) $prodMax),
            'iron' => $ratio((float) max(0, (int) ($production['iron'] ?? 0)), (float) $prodMax),
            'gold' => $ratio((float) max(0, (int) ($production['gold'] ?? 0)), (float) $prodMax),
        ];

        $this->render('town/stats.html', [
            'page_title' => $town['name'] . ' - Stats',
            'town' => $town,
            'building_levels' => $buildingLevels,
            'resources' => $resources,
            'limits' => $limits,
            'production' => $production,
            'crop_net' => $cropNet,
            'resource_fill' => $resourceFill,
            'production_fill' => $productionFill,
            'army' => $army,
            'weapons' => $townService->parseWeapons($town['weapons']),
            'buildings' => $buildings,
        ]);
    }

    public function showEdit(\Base $f3, array $params): void
    {
        $townId = (int) ($params['id'] ?? 0);

        $town = $this->requireOwnedTown($townId);
        if ($town === null) return;
        $coords = $this->townService()->getTownCoords($townId);
        $moveCostCrop = max(0, (int) ($f3->get('game.move_cost_crop') ?? 100));

        $this->render('town/edit.html', [
            'page_title' => $f3->get('lang.townEdit') ?? 'Edit Town',
            'town' => $town,
            'town_x' => (int) ($coords['x'] ?? 0),
            'town_y' => (int) ($coords['y'] ?? 0),
            'move_cost_crop' => $moveCostCrop,
        ]);
    }

    public function edit(\Base $f3, array $params): void
    {
        $townId = (int) ($params['id'] ?? 0);
        $user = $this->requireAuth();
        if ($user === null) return;

        if (!$this->requireCsrf('/town/' . $townId)) return;

        if (!$this->requireTownOwnership($townId)) return;

        $townService = $this->townService();

        $name = InputSanitizer::clean($this->post('name', ''));
        $description = InputSanitizer::clean($this->post('description', ''));

        if (!empty($name)) {
            $townService->updateName($townId, $name);
        }

        $townService->updateDescription($townId, $description);

        $this->flashAndRedirect('Town updated.', '/town/' . $townId);
    }

    public function setCapital(\Base $f3, array $params): void
    {
        $townId = (int) ($params['id'] ?? 0);
        $user = $this->requireAuth();
        if ($user === null) return;

        if (!$this->requireCsrf('/towns')) return;

        if (!$this->requireTownOwnership($townId)) return;

        $this->townService()->setCapital($user['id'], $townId);
        $this->flashAndRedirect('Capital set.', '/towns');
    }

    public function abandon(\Base $f3, array $params): void
    {
        $townId = (int) ($params['id'] ?? 0);
        $user = $this->requireAuth();
        if ($user === null) return;

        if (!$this->requireCsrf('/town/' . $townId)) return;

        $town = $this->requireOwnedTown($townId);
        if ($town === null) return;

        if ((int) $town['isCapital'] === 1) {
            $this->flashAndRedirect('You cannot abandon your capital.', '/town/' . $townId);
            return;
        }

        // Clean up all queues for the abandoned town
        $this->db->exec('DELETE FROM c_queue WHERE town = ?', [$townId]);
        $this->db->exec('DELETE FROM u_queue WHERE town = ?', [$townId]);
        $this->db->exec('DELETE FROM w_queue WHERE town = ?', [$townId]);
        $this->db->exec('DELETE FROM uup_queue WHERE town = ?', [$townId]);
        $this->db->exec('DELETE FROM t_queue WHERE seller = ? AND type = 0', [$townId]);
        $this->db->exec('DELETE FROM a_queue WHERE town = ?', [$townId]);

        // Set owner to 0 (abandoned)
        $this->db->exec('UPDATE towns SET owner = 0 WHERE id = ?', [$townId]);

        // Recalculate user points after abandon
        (new UserService($this->db))->recalculatePoints($user['id']);

        $this->flashAndRedirect('Town abandoned.', '/towns');
    }

    public function move(\Base $f3, array $params): void
    {
        $townId = (int) ($params['id'] ?? 0);
        $user = $this->requireAuth();
        if ($user === null) return;

        if (!$this->requireCsrf('/town/' . $townId . '/edit')) return;
        if (!$this->requireTownOwnership($townId, '/towns')) return;

        $x = InputSanitizer::cleanInt($this->post('x', 0));
        $y = InputSanitizer::cleanInt($this->post('y', 0));
        $cost = max(0, (int) ($f3->get('game.move_cost_crop') ?? 100));

        $result = $this->townService()->moveTown($townId, (int) $user['id'], $x, $y, $cost);
        $this->flashAndRedirect($result['message'], $result['ok'] ? '/town/' . $townId : '/town/' . $townId . '/edit');
    }

    public function saveLayout(\Base $f3, array $params): void
    {
        $townId = (int) ($params['id'] ?? 0);

        if (!$this->validateCsrfToken()) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Invalid request.']);
            return;
        }

        $user = $this->requireAuth();

        if ($user === null) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Not authenticated']);
            return;
        }

        $townService = $this->townService();

        if (!$townService->isOwnedBy($townId, $user['id'])) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Not authorized']);
            return;
        }

        $body = json_decode($f3->get('BODY'), true);
        if (!is_array($body)) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Invalid JSON']);
            return;
        }

        // Load current layout as base
        $town = $townService->findById($townId);
        $current = $townService->parseLayout($town['layout'] ?? null);

        // Add coordinates for biome-aware background restrictions
        $coords = $townService->getTownCoords($townId);
        $town['x'] = (int) ($coords['x'] ?? 0);
        $town['y'] = (int) ($coords['y'] ?? 0);

        // Update background if provided and valid (restricted by town biome)
        $bgDir = $f3->get('ROOT') . '/default/1/';
        $allowedBackgrounds = $this->getAllowedTownBackgrounds($town, $bgDir, (string) ($current['background'] ?? ''));
        if (isset($body['background']) && in_array($body['background'], $allowedBackgrounds, true)) {
            $requestedBg = basename((string) $body['background']);
            $catalog = $this->getTownBackgroundPremiumCatalog($allowedBackgrounds);
            $ownedIds = $this->getOwnedTownBackgroundProductIds((int) $user['id']);
            if ($this->isTownBackgroundUsable($requestedBg, $catalog, $ownedIds)) {
                $current['background'] = $requestedBg;
            }
        }

        // Update positions if provided
        if (isset($body['positions']) && is_array($body['positions'])) {
            $activeBackground = (string) ($current['background'] ?? 'back.png');
            $blockedZones = $this->getBackgroundMask($activeBackground);
            foreach ($body['positions'] as $idx => $pos) {
                $i = (int) $idx;
                if ($i < 0 || $i > 21 || !is_array($pos)) {
                    continue;
                }
                $x = max(0, min(565, (int) ($pos['x'] ?? 0)));
                $y = max(0, min(272, (int) ($pos['y'] ?? 0)));
                if ($this->isPointInsideBlockedZone($x, $y, $blockedZones)) {
                    continue;
                }
                $current['positions'][$i] = ['x' => $x, 'y' => $y];
            }
        }

        $townService->updateLayout($townId, json_encode($current));

        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
    }

    public function buyBackground(\Base $f3, array $params): void
    {
        $townId = (int) ($params['id'] ?? 0);

        if (!$this->validateCsrfToken()) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Invalid request.']);
            return;
        }

        $user = $this->requireAuth();
        if ($user === null) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Not authenticated']);
            return;
        }

        if (!$this->townService()->isOwnedBy($townId, (int) $user['id'])) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Not authorized']);
            return;
        }

        $bg = trim((string) $this->post('background', ''));
        if ($bg === '') {
            $body = json_decode((string) $f3->get('BODY'), true);
            if (is_array($body)) {
                $bg = trim((string) ($body['background'] ?? ''));
            }
        }
        $bg = basename($bg);
        if ($bg === '') {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Invalid background']);
            return;
        }

        $town = $this->townService()->findById($townId);
        $coords = $this->townService()->getTownCoords($townId);
        $town['x'] = (int) ($coords['x'] ?? 0);
        $town['y'] = (int) ($coords['y'] ?? 0);
        $layout = $this->townService()->parseLayout($town['layout'] ?? null);
        $bgDir = $f3->get('ROOT') . '/default/1/';
        $allowed = $this->getAllowedTownBackgrounds($town, $bgDir, (string) ($layout['background'] ?? ''));
        if (!in_array($bg, $allowed, true)) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Background is not available for this town.']);
            return;
        }

        $catalog = $this->getTownBackgroundPremiumCatalog($allowed);
        $meta = $catalog[$bg] ?? null;
        $productId = (int) ($meta['product_id'] ?? 0);
        if ($productId <= 0) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Background is not premium.']);
            return;
        }

        $ownedIds = $this->getOwnedTownBackgroundProductIds((int) $user['id']);
        if (in_array($productId, $ownedIds, true)) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'already' => true, 'premium_balance' => (int) ($user['premium_balance'] ?? 0)]);
            return;
        }

        try {
            $service = new PremiumStoreService($this->db);
            $result = $service->purchaseProduct((int) $user['id'], $productId);
            $newBalance = (int) ($result['balance'] ?? 0);
            $this->updateSession('premium_balance', $newBalance);
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'premium_balance' => $newBalance]);
            return;
        } catch (\RuntimeException $e) {
            header('Content-Type: application/json');
            echo json_encode(['error' => $this->translateKey($e->getMessage())]);
            return;
        } catch (\Throwable $e) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Purchase failed.']);
            return;
        }
    }

    private function layoutHasExplicitBackground(?string $layout): bool
    {
        if ($layout === null || $layout === '') {
            return false;
        }
        $decoded = json_decode($layout, true);
        return is_array($decoded) && isset($decoded['background']) && is_string($decoded['background']) && $decoded['background'] !== '';
    }

    private function detectTownBiomeKey(int $x, int $y): string
    {
        // Inspect surrounding map tiles since town center tile is type=3
        $rows = $this->db->exec(
            'SELECT type, subtype, COUNT(*) AS cnt
             FROM map
             WHERE x BETWEEN ? AND ? AND y BETWEEN ? AND ?
               AND NOT (x = ? AND y = ?)
               AND type <> 3
             GROUP BY type, subtype
             ORDER BY cnt DESC
             LIMIT 1',
            [$x - 1, $x + 1, $y - 1, $y + 1, $x, $y]
        );

        if (!empty($rows)) {
            $type = (int) ($rows[0]['type'] ?? 1);
            $subtype = (int) ($rows[0]['subtype'] ?? 0);
            return $type . '_' . $subtype;
        }

        return '1_0';
    }

    private function getConfigValue(string $name, string $default = ''): string
    {
        $row = $this->db->exec('SELECT value FROM config WHERE name = ? ORDER BY ord ASC LIMIT 1', [$name]);
        return (string) ($row[0]['value'] ?? $default);
    }

    private function listAvailableBackgrounds(string $bgDir): array
    {
        $patterns = ['back*.png', 'back*.jpg', 'back*.jpeg', 'back*.webp', 'back*.gif'];
        $files = [];
        foreach ($patterns as $pattern) {
            $matched = glob($bgDir . $pattern) ?: [];
            foreach ($matched as $m) {
                $files[] = $m;
            }
        }
        $names = array_values(array_unique(array_filter(array_map('basename', $files), static function (string $name): bool {
            return (bool) preg_match('/^back(\d*)\.(png|jpe?g|webp|gif)$/i', $name);
        })));
        sort($names);
        return $names;
    }

    /**
     * Restrict selectable backgrounds to biome/default set for the town.
     *
     * @param array<string, mixed> $town
     * @return array<int, string>
     */
    private function getAllowedTownBackgrounds(array $town, string $bgDir, string $currentBackground = ''): array
    {
        $available = $this->listAvailableBackgrounds($bgDir);
        if (empty($available)) {
            return [];
        }

        $biomeKey = $this->detectTownBiomeKey((int) ($town['x'] ?? 0), (int) ($town['y'] ?? 0));
        $defaultBg = $this->getConfigValue('town_background_default', 'back.png');
        $biomeBg = $this->getConfigValue('town_background_biome_' . $biomeKey, '');

        $allowed = [];
        if ($biomeBg !== '' && in_array($biomeBg, $available, true)) {
            $allowed[] = $biomeBg;
        }
        if ($defaultBg !== '' && in_array($defaultBg, $available, true)) {
            $allowed[] = $defaultBg;
        }
        if ($currentBackground !== '' && in_array($currentBackground, $available, true)) {
            $allowed[] = $currentBackground;
        }

        $allowed = array_values(array_unique($allowed));
        return !empty($allowed) ? $allowed : $available;
    }

    private function getBackgroundMaskConfigKey(string $background): string
    {
        $safe = strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', basename($background)));
        $safe = trim($safe, '_');
        return 'town_background_mask_' . $safe;
    }

    /**
     * @param mixed $raw
     * @return array<int, array<string, int|string>>
     */
    private function normalizeBackgroundMask($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $validFx = ['none', 'water', 'fire', 'wind', 'smoke', 'road'];
        $out = [];
        foreach ($raw as $zone) {
            if (!is_array($zone)) {
                continue;
            }
            $type  = (string) ($zone['t'] ?? 'rect');
            $rawFx = (string) ($zone['fx'] ?? 'none');
            $fx    = in_array($rawFx, $validFx, true) ? $rawFx : 'none';
            if ($type === 'rect' || !isset($zone['t'])) {
                $x = max(0, min(639, (int) ($zone['x'] ?? 0)));
                $y = max(0, min(371, (int) ($zone['y'] ?? 0)));
                $w = max(1, min(640 - $x, (int) ($zone['w'] ?? 0)));
                $h = max(1, min(372 - $y, (int) ($zone['h'] ?? 0)));
                if ($w < 4 || $h < 4) {
                    continue;
                }
                $out[] = ['t' => 'rect', 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'fx' => $fx];
                continue;
            }
            if ($type === 'circle' || $type === 'brush') {
                $cx = max(0, min(639, (int) ($zone['cx'] ?? $zone['x'] ?? 0)));
                $cy = max(0, min(371, (int) ($zone['cy'] ?? $zone['y'] ?? 0)));
                $r = max(2, min(120, (int) ($zone['r'] ?? 0)));
                $out[] = ['t' => $type, 'cx' => $cx, 'cy' => $cy, 'r' => $r, 'fx' => $fx];
                continue;
            }
            if ($type === 'poly' && isset($zone['points']) && is_array($zone['points'])) {
                $points = [];
                foreach ($zone['points'] as $pt) {
                    if (!is_array($pt)) {
                        continue;
                    }
                    $px = max(0, min(639, (int) ($pt['x'] ?? 0)));
                    $py = max(0, min(371, (int) ($pt['y'] ?? 0)));
                    $points[] = ['x' => $px, 'y' => $py];
                }
                if (count($points) >= 3) {
                    if (count($points) > 80) {
                        $points = array_slice($points, 0, 80);
                    }
                    $out[] = ['t' => 'poly', 'points' => $points, 'fx' => $fx];
                }
            }
        }
        return $out;
    }

    /**
     * @return array<int, array{x:int,y:int,w:int,h:int}>
     */
    private function getBackgroundMask(string $background): array
    {
        $key = $this->getBackgroundMaskConfigKey($background);
        $raw = $this->getConfigValue($key, '[]');
        return $this->normalizeBackgroundMask(json_decode($raw, true));
    }

    /**
     * @param array<int, string> $backgrounds
     * @return array<string, array<int, array<string, int|string>>>
     */
    private function getBackgroundMasksForBackgrounds(array $backgrounds): array
    {
        $result = [];
        foreach ($backgrounds as $bg) {
            $name = basename((string) $bg);
            $result[$name] = $this->getBackgroundMask($name);
        }
        return $result;
    }

    /**
     * @param array<int, string> $backgrounds
     * @return array<string, array{product_id:int,price_credits:int}>
     */
    private function getTownBackgroundPremiumCatalog(array $backgrounds): array
    {
        $backgrounds = array_values(array_unique(array_filter(array_map(static fn($v) => basename((string) $v), $backgrounds))));
        if (empty($backgrounds)) {
            return [];
        }
        try {
            $ph = implode(',', array_fill(0, count($backgrounds), '?'));
            $params = array_merge(['town_background'], $backgrounds);
            $rows = $this->db->exec(
                "SELECT id, icon, price_credits
                 FROM premium_products
                 WHERE slot = ? AND is_active = 1 AND icon IN ({$ph})",
                $params
            );
        } catch (\Throwable $e) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $name = basename((string) ($row['icon'] ?? ''));
            if ($name === '') {
                continue;
            }
            $out[$name] = [
                'product_id' => (int) ($row['id'] ?? 0),
                'price_credits' => max(0, (int) ($row['price_credits'] ?? 0)),
            ];
        }
        return $out;
    }

    /**
     * @return array<int, int>
     */
    private function getOwnedTownBackgroundProductIds(int $userId): array
    {
        try {
            $rows = $this->db->exec(
                'SELECT i.product_id
                 FROM user_inventory i
                 INNER JOIN premium_products p ON p.id = i.product_id
                 WHERE i.user_id = ? AND p.slot = ?',
                [$userId, 'town_background']
            );
        } catch (\Throwable $e) {
            return [];
        }
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int) ($row['product_id'] ?? 0);
        }
        return $ids;
    }

    /**
     * @param array<string, array{product_id:int,price_credits:int}> $catalog
     * @param array<int, int> $ownedProductIds
     */
    private function isTownBackgroundUsable(string $background, array $catalog, array $ownedProductIds): bool
    {
        $meta = $catalog[$background] ?? null;
        if ($meta === null) {
            return true;
        }
        $productId = (int) ($meta['product_id'] ?? 0);
        if ($productId <= 0) {
            return true;
        }
        return in_array($productId, $ownedProductIds, true);
    }

    /**
     * @param array<int, array<string, int|string>> $zones
     */
    private function isPointInsideBlockedZone(int $x, int $y, array $zones): bool
    {
        foreach ($zones as $z) {
            $type = (string) ($z['t'] ?? 'rect');
            if ($type === 'rect') {
                if (
                    $x >= (int) ($z['x'] ?? 0) &&
                    $x <= ((int) ($z['x'] ?? 0) + (int) ($z['w'] ?? 0)) &&
                    $y >= (int) ($z['y'] ?? 0) &&
                    $y <= ((int) ($z['y'] ?? 0) + (int) ($z['h'] ?? 0))
                ) {
                    return true;
                }
                continue;
            }
            if ($type === 'circle' || $type === 'brush') {
                $cx = (int) ($z['cx'] ?? 0);
                $cy = (int) ($z['cy'] ?? 0);
                $r = (int) ($z['r'] ?? 0);
                $dx = $x - $cx;
                $dy = $y - $cy;
                if (($dx * $dx) + ($dy * $dy) <= ($r * $r)) {
                    return true;
                }
                continue;
            }
            if ($type === 'poly' && isset($z['points']) && is_array($z['points'])) {
                if ($this->isPointInPolygon($x, $y, $z['points'])) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $points
     */
    private function isPointInPolygon(int $x, int $y, array $points): bool
    {
        $inside = false;
        $count = count($points);
        if ($count < 3) {
            return false;
        }
        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $xi = (int) ($points[$i]['x'] ?? 0);
            $yi = (int) ($points[$i]['y'] ?? 0);
            $xj = (int) ($points[$j]['x'] ?? 0);
            $yj = (int) ($points[$j]['y'] ?? 0);
            $intersect = (($yi > $y) !== ($yj > $y))
                && ($x < (($xj - $xi) * ($y - $yi)) / max(1e-6, ($yj - $yi)) + $xi);
            if ($intersect) {
                $inside = !$inside;
            }
        }
        return $inside;
    }
}

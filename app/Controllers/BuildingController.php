<?php declare(strict_types=1);

namespace Devana\Controllers;

use Devana\Helpers\DataParser;
use Devana\Services\BuildingService;
use Devana\Services\ResourceService;
use Devana\Services\QueueService;

final class BuildingController extends Controller
{
    private const BARRACKS_UNITS = [0, 1, 2, 3, 4, 5, 6, 11, 12];
    private const SIEGE_UNITS = [7, 8];
    private const PORT_UNITS = [9, 10];
    private const WAR_SHOP_WEAPONS = [0, 1, 2, 3, 4, 5, 6, 7, 8, 10];
    private const STABLE_WEAPONS = [9];

    /**
     * @param list<array<string,mixed>> $buildings
     * @return array<int,array<string,mixed>>
     */
    private function indexBuildingsByType(array $buildings): array
    {
        $indexed = [];
        foreach ($buildings as $row) {
            $indexed[(int) ($row['type'] ?? -1)] = $row;
        }
        return $indexed;
    }

    public function view(\Base $f3, array $params): void
    {
        $townId = (int) ($params['id'] ?? 0);
        $type = (int) ($params['type'] ?? 0);
        $user = $this->currentUser();

        $town = $this->requireOwnedTown($townId);
        if ($town === null) return;

        $townService = $this->townService();
        $buildingService = new BuildingService($this->db);
        $building = $buildingService->getByTypeAndFaction($type, $user['faction']);

        if ($building === null) {
            $this->flashAndRedirect('Building not found.', '/town/' . $townId);
            return;
        }

        // Load language early so we can translate DB data
        $this->loadLanguage();
        $lang = $this->f3->get('lang');
        $faction = $user['faction'];

        // Translate building name and description from language file
        $building['name'] = $this->langCatalogText($lang, 'buildings', (int) $faction, $type, 0, (string) ($building['name'] ?? ''));
        $building['description'] = $this->langCatalogText($lang, 'buildings', (int) $faction, $type, 1, (string) ($building['description'] ?? ''));

        $buildingLevels = $townService->parseBuildingLevels($town['buildings']);
        $resources = $townService->parseResources($town['resources']);
        $limits = $townService->parseLimits($town['limits']);
        $production = $townService->parseProduction($town['production']);
        $land = $townService->parseLand($town['land']);
        $currentLevel = $buildingLevels[$type] ?? 0;

        // Determine template based on building type
        $template = $this->resolveTemplate($type);

        // Get queue info
        $queueService = new QueueService($this->db);
        $constructionQueue = $queueService->getConstructionQueue($townId);
        $weaponQueue = $queueService->getWeaponQueue($townId);
        $unitQueue = $queueService->getUnitQueue($townId);

        // Get faction units/weapons for military buildings
        $units = [];
        $weapons = [];
        if (in_array($type, [12, 15, 16, 17, 18, 19, 20], true)) {
            $units = $this->db->exec('SELECT * FROM units WHERE faction = ? ORDER BY type', [$user['faction']]);
            $weapons = $this->db->exec('SELECT * FROM weapons WHERE faction = ? ORDER BY type', [$user['faction']]);

            // Translate unit and weapon names from language file
            foreach ($units as &$u) {
                $uType = (int) $u['type'];
                $u['name'] = $this->langCatalogText($lang, 'units', (int) $faction, $uType, 0, (string) $u['name']);
            }
            unset($u);
            foreach ($weapons as &$w) {
                $wType = (int) $w['type'];
                $w['name'] = $this->langCatalogText($lang, 'weapons', (int) $faction, $wType, 0, (string) $w['name']);
            }
            unset($w);
        }

        // Enrich building data for template
        $building['level'] = $currentLevel;
        $durations = explode('-', $building['duration']);
        $building['max_level'] = count($durations);
        $building['slot'] = $type;

        // Parse output for production at current and next level
        $outputs = explode('-', $building['output'] ?? '');
        $building['production'] = $outputs[$currentLevel] ?? 0;
        $building['next_production'] = $outputs[$currentLevel + 1] ?? $outputs[$currentLevel] ?? 0;

        // Parse upkeep for bonus display (special buildings)
        $upkeeps = explode('-', $building['upkeep'] ?? '');
        $building['bonus'] = $upkeeps[$currentLevel] ?? '';
        $building['next_bonus'] = $upkeeps[$currentLevel + 1] ?? '';

        // Storage buildings use output as capacity
        $building['capacity'] = $outputs[$currentLevel] ?? 0;
        $building['next_capacity'] = $outputs[$currentLevel + 1] ?? $outputs[$currentLevel] ?? 0;

        // Parse cost for upgrade
        $costParts = DataParser::toIntArray($building['input']);
        $upgradeCost = [
            'crop' => $costParts[0] ?? 0,
            'lumber' => $costParts[1] ?? 0,
            'stone' => $costParts[2] ?? 0,
            'iron' => $costParts[3] ?? 0,
            'gold' => $costParts[4] ?? 0,
            'time' => $durations[$currentLevel] ?? '0:00',
        ];

        // Check if player can afford upgrade
        $canUpgrade = true;
        $resKeys = ['crop', 'lumber', 'stone', 'iron', 'gold'];
        foreach ($resKeys as $i => $key) {
            if (($resources[$key] ?? 0) < ($costParts[$i] ?? 0)) {
                $canUpgrade = false;
                break;
            }
        }

        // Build trainable units and forgeable weapons for military buildings
        $trainableUnits = [];
        $forgeableWeapons = [];
        $trainQueue = [];

        if (in_array($type, [12, 15, 16, 17, 18, 19, 20], true)) {
            // Determine which unit/weapon types this building can handle
            $allowedUnits = match ($type) {
                15 => self::BARRACKS_UNITS,
                20 => self::SIEGE_UNITS,
                12 => self::PORT_UNITS,
                default => null,
            };
            $allowedWeapons = match ($type) {
                18 => self::WAR_SHOP_WEAPONS,
                19 => self::STABLE_WEAPONS,
                default => null,
            };

            // Load research data for unit training requirement
            $uUpgTrain = DataParser::toIntArray($town['uUpgrades']);
            $wUpgTrain = DataParser::toIntArray($town['wUpgrades']);
            $aUpgTrain = DataParser::toIntArray($town['aUpgrades']);

            foreach ($units as $u) {
                $uTypeId = (int) $u['type'];
                if ($allowedUnits !== null && !in_array($uTypeId, $allowedUnits, true)) {
                    continue;
                }
                $costParts2 = DataParser::toIntArray($u['input']);
                $isResearched = ($uUpgTrain[$uTypeId] ?? 0) >= 1
                    && ($wUpgTrain[$uTypeId] ?? 0) >= 1
                    && ($aUpgTrain[$uTypeId] ?? 0) >= 1;
                $trainableUnits[] = [
                    'id' => $uTypeId,
                    'name' => $u['name'],
                    'attack' => (int) $u['attack'],
                    'defense' => (int) $u['defense'],
                    'cost_crop' => $costParts2[0] ?? 0,
                    'cost_lumber' => $costParts2[1] ?? 0,
                    'cost_iron' => $costParts2[3] ?? 0,
                    'time' => $u['duration'],
                    'researched' => $isResearched,
                ];
            }
            foreach ($weapons as $w) {
                $wTypeId = (int) $w['type'];
                if ($allowedWeapons !== null && !in_array($wTypeId, $allowedWeapons, true)) {
                    continue;
                }
                $wCost = DataParser::toIntArray($w['input']);
                $forgeableWeapons[] = [
                    'id' => $wTypeId,
                    'name' => $w['name'],
                    'bonus' => (int) ($w['attack'] ?? 0),
                    'cost_crop' => $wCost[0] ?? 0,
                    'cost_iron' => $wCost[3] ?? 0,
                    'cost_gold' => $wCost[4] ?? 0,
                    'time' => $w['duration'] ?? '0:00',
                ];
            }
            // Get training queue
            foreach ($unitQueue as $q) {
                $unitName = '';
                foreach ($units as $u) {
                    if ((int) $u['type'] === (int) $q['type']) {
                        $unitName = $u['name'];
                        break;
                    }
                }
                $trainQueue[] = [
                    'id' => $q['id'] ?? 0,
                    'name' => $unitName,
                    'quantity' => $q['quantity'] ?? 1,
                    'timeLeft' => $q['dueTime'] ?? '',
                ];
            }
        }

        // Build extra data for Academy/Blacksmith (upgrade buildings)
        $upgradeUnits = [];
        $upgradeQueue = [];
        if (in_array($type, [16, 17], true) && $currentLevel > 0) {
            $uUpgrades = DataParser::toIntArray($town['uUpgrades']);
            $wUpgrades = DataParser::toIntArray($town['wUpgrades']);
            $aUpgrades = DataParser::toIntArray($town['aUpgrades']);
            $upgradeStatus = $queueService->getUpgradeStatus($townId);

            foreach ($units as $u) {
                $uType = (int) $u['type'];
                $uCostParts = DataParser::toIntArray($u['input']);
                $uLevel = $uUpgrades[$uType] ?? 0;
                $wLevel = $wUpgrades[$uType] ?? 0;
                $aLevel = $aUpgrades[$uType] ?? 0;

                $upgradeUnits[] = [
                    'type' => $uType,
                    'name' => $u['name'],
                    'current_hp' => (int) ($u['hp'] ?? 0) + $uLevel,
                    'next_hp' => (int) ($u['hp'] ?? 0) + $uLevel + 1,
                    'current_atk' => (int) ($u['attack'] ?? 0) + $wLevel,
                    'current_def' => (int) ($u['defense'] ?? 0) + $aLevel,
                    'u_level' => $uLevel,
                    'w_level' => $wLevel,
                    'a_level' => $aLevel,
                    'is_upgrading' => isset($upgradeStatus[$uType]),
                    'upgrade_cost' => implode('/', array_map(fn(int $c) => (string) floor($c * ($uLevel + 1)), $uCostParts)),
                    'w_upgrade_cost' => implode('/', array_map(fn(int $c) => (string) floor($c * ($wLevel + 1)), $uCostParts)),
                    'a_upgrade_cost' => implode('/', array_map(fn(int $c) => (string) floor($c * ($aLevel + 1)), $uCostParts)),
                    'duration' => $u['duration'],
                ];
            }

            $rawUpgradeQueue = $queueService->getUpgradeQueue($townId);
            $treeNames = [17 => 'HP', 18 => 'ATK', 19 => 'DEF'];
            foreach ($rawUpgradeQueue as $uq) {
                $unitName = '';
                foreach ($units as $u) {
                    if ((int) $u['type'] === (int) $uq['unit']) {
                        $unitName = $u['name'];
                        break;
                    }
                }
                $upgradeQueue[] = [
                    'unit' => $uq['unit'],
                    'tree' => $uq['tree'],
                    'name' => $unitName,
                    'tree_name' => $treeNames[(int) $uq['tree']] ?? '',
                    'timeLeft' => $uq['timeLeft'],
                ];
            }
        }

        // Build extra data for Hall page (b7)
        $allBuildings = [];
        $demolishData = [];
        if ($type === 7) {
            $buildings2 = $buildingService->getByFaction($user['faction']);
            $buildingsByType = $this->indexBuildingsByType($buildings2);
            foreach ($buildings2 as $b2) {
                $bType = (int) $b2['type'];
                $bLevel = $buildingLevels[$bType] ?? 0;
                if ($bLevel === 0 && !$queueService->isConstructionQueued($townId, $bType, -1)) {
                    // Not built yet, show as buildable
                    $bCost = DataParser::toIntArray($b2['input']);
                    $canAfford = true;
                    $resKeys = ['crop', 'lumber', 'stone', 'iron', 'gold'];
                    foreach ($resKeys as $ci => $ck) {
                        if (($resources[$ck] ?? 0) < ($bCost[$ci] ?? 0)) {
                            $canAfford = false;
                            break;
                        }
                    }
                    $reqsMet = $buildingService->requirementsMet($b2['requirements'], $buildingLevels);
                    // Use translated name/desc from language file if available
                    $bName = $b2['name'];
                    $bDesc = $b2['description'] ?? '';
                    $bName = $this->langCatalogText($lang, 'buildings', (int) $faction, $bType, 0, (string) $bName);
                    $bDesc = $this->langCatalogText($lang, 'buildings', (int) $faction, $bType, 1, (string) $bDesc);
                    $nameParts = explode('-', $bName);

                    // Build human-readable requirements list
                    $reqsList = [];
                    if (!empty($b2['requirements'])) {
                        $reqs = explode('/', $b2['requirements']);
                        foreach ($reqs as $req) {
                            $rParts = explode('-', $req);
                            $rType = (int) $rParts[0];
                            $rLevel = (int) ($rParts[1] ?? 1);
                            $rFallback = (string) (($buildingsByType[$rType]['name'] ?? null) ?: ('Building ' . $rType));
                            $rName = $this->langCatalogText($lang, 'buildings', (int) $faction, $rType, 0, $rFallback);
                            $rNameParts = explode('-', $rName);
                            $reqsList[] = $rNameParts[0] . ' ' . ($lang['level'] ?? 'Level') . ' ' . $rLevel;
                        }
                    }

                    $allBuildings[] = [
                        'type' => $bType,
                        'name' => $nameParts[0],
                        'desc' => $bDesc,
                        'can_build' => $reqsMet,
                        'affordable' => $canAfford && $reqsMet,
                        'requirements' => implode(', ', $reqsList),
                    ];
                }
            }

            // Demolish data for JS
            $demolishData['land_0'] = implode(',', $land[0] ?? []);
            $demolishData['land_1'] = implode(',', $land[1] ?? []);
            $demolishData['land_2'] = implode(',', $land[2] ?? []);
            $demolishData['land_3'] = implode(',', $land[3] ?? []);
            $bPairs = [];
            foreach ($buildingLevels as $bi => $bv) {
                $bPairs[] = $bi;
                $bPairs[] = $bv;
            }
            $demolishData['buildings'] = implode(',', $bPairs);
        }

        $this->render($template, [
            'page_title' => $building['name'],
            'town' => $town,
            'building' => $building,
            'type' => $type,
            'current_level' => $currentLevel,
            'building_levels' => $buildingLevels,
            'resources' => $resources,
            'limits' => $limits,
            'production' => $production,
            'land' => $land,
            'construction_queue' => $constructionQueue,
            'weapon_queue' => $weaponQueue,
            'unit_queue' => $unitQueue,
            'units' => $units,
            'weapons' => $weapons,
            'town_weapons' => $townService->parseWeapons($town['weapons']),
            'town_army' => $townService->parseArmy($town['army']),
            'upgrade_cost' => $upgradeCost,
            'can_upgrade' => $canUpgrade,
            'trainable_units' => $trainableUnits,
            'forgeable_weapons' => $forgeableWeapons,
            'train_queue' => $trainQueue,
            // Academy/Blacksmith upgrade data
            'upgrade_units' => $upgradeUnits,
            'upgrade_queue' => $upgradeQueue,
            // Hall page data
            'all_buildings' => $allBuildings,
            'demolish_land_0' => $demolishData['land_0'] ?? '',
            'demolish_land_1' => $demolishData['land_1'] ?? '',
            'demolish_land_2' => $demolishData['land_2'] ?? '',
            'demolish_land_3' => $demolishData['land_3'] ?? '',
            'demolish_buildings' => $demolishData['buildings'] ?? '',
        ]);
    }

    public function action(\Base $f3, array $params): void
    {
        $townId = (int) ($params['id'] ?? 0);
        $type = (int) ($params['type'] ?? 0);
        $user = $this->currentUser();

        if (!$this->requireCsrf('/town/' . $townId . '/building/' . $type)) return;

        if (!$this->requireTownOwnership($townId)) return;

        $action = $this->post('action', '');

        match ($action) {
            'build' => $this->handleBuild($townId, $type, $user['faction']),
            'upgrade' => $this->handleUpgrade($townId, $type, $user['faction']),
            'train' => $this->handleTrain($townId, $type, $user['faction']),
            'forge' => $this->handleForge($townId, $type, $user['faction']),
            default => null,
        };

        $this->redirect('/town/' . $townId . '/building/' . $type);
    }

    private function handleBuild(int $townId, int $type, int $factionId): void
    {
        $subB = (int) $this->post('subB', -1);
        $this->loadLanguage();
        $lang = $this->f3->get('lang');

        $buildingService = new BuildingService($this->db);
        $building = $buildingService->getByTypeAndFaction($type, $factionId);

        if ($building === null) {
            return;
        }

        $townService = $this->townService();
        $town = $townService->findById($townId);
        $buildingLevels = $townService->parseBuildingLevels($town['buildings']);

        if (!$buildingService->requirementsMet($building['requirements'], $buildingLevels)) {
            $this->f3->set('SESSION.flash', $lang['reqsNotMet'] ?? 'Requirements not met.');
            $this->f3->set('SESSION.flash_type', 'error');
            return;
        }

        $resourceService = new ResourceService($this->db);

        if (!$resourceService->hasEnough($town['resources'], $building['input'])) {
            $this->f3->set('SESSION.flash', $lang['notEnoughRes'] ?? 'Not enough resources.');
            $this->f3->set('SESSION.flash_type', 'error');
            return;
        }

        // Determine level for duration (land upgrades also scale by current level).
        if ($subB > -1) {
            $land = $townService->parseLand($town['land']);
            $level = (int) ($land[$type][$subB] ?? 0);
        } else {
            $level = (int) ($buildingLevels[$type] ?? 0);
        }
        $durations = explode('-', $building['duration']);
        $duration = $durations[$level] ?? '0:5';

        // Apply construction speed modifier from Hall (limits[4])
        $lim = DataParser::toIntArray($town['limits']);
        $speedPct = max(1, $lim[4] ?? 100);
        if ($speedPct !== 100) {
            $dSec = DataParser::durationToSeconds($duration);
            $dSec = max(60, (int) ($dSec * $speedPct / 100));
            $duration = DataParser::secondsToDuration($dSec);
        }

        $queueService = new QueueService($this->db);

        // Check if already in queue
        if ($queueService->isConstructionQueued($townId, $type, $subB)) {
            $this->f3->set('SESSION.flash', $lang['alreadyInConstructionQueue'] ?? 'Already in construction queue.');
            $this->f3->set('SESSION.flash_type', 'error');
            return;
        }

        // Queue limit for normal users (can be extended for memberships later)
        $maxConstructionQueue = 3;
        $added = $queueService->addConstruction($townId, $type, $subB, $duration, $maxConstructionQueue);
        if (!$added) {
            $this->f3->set('SESSION.flash', $lang['constructionQueueFull'] ?? 'Construction queue is full (max 3).');
            $this->f3->set('SESSION.flash_type', 'error');
            return;
        }

        // Deduct cost after queue insert succeeds
        $resourceService->deductResources($townId, $building['input']);
        $this->f3->set('SESSION.flash', $lang['upgradeQueued'] ?? 'Upgrade queued.');
        $this->f3->set('SESSION.flash_type', 'success');
    }

    private function handleUpgrade(int $townId, int $type, int $factionId): void
    {
        $this->handleBuild($townId, $type, $factionId);
    }

    private function handleTrain(int $townId, int $type, int $factionId): void
    {
        $this->loadLanguage();
        $lang = $this->f3->get('lang');

        // Template sends qty[unitId] = amount for each unit type
        $qtyArray = $this->post('qty', []);
        if (!is_array($qtyArray)) {
            return;
        }

        $allowedUnits = match ($type) {
            15 => self::BARRACKS_UNITS,
            20 => self::SIEGE_UNITS,
            12 => self::PORT_UNITS,
            default => [],
        };

        $trained = 0;
        foreach ($qtyArray as $unitType => $rawQty) {
            $unitType = (int) $unitType;
            $quantity = max(0, (int) $rawQty);
            if ($quantity === 0) {
                continue;
            }

            if (!in_array($unitType, $allowedUnits, true)) {
                continue;
            }

            $unit = $this->db->exec(
                'SELECT * FROM units WHERE type = ? AND faction = ?',
                [$unitType, $factionId]
            );
            if (empty($unit)) {
                continue;
            }
            $unit = $unit[0];

            // Reload town each iteration (resources/weapons may have changed)
            $town = $this->townService()->findById($townId);

            // Research requirement: all 3 upgrade trees must be level 1+
            $uUpg = DataParser::toIntArray($town['uUpgrades']);
            $wUpg = DataParser::toIntArray($town['wUpgrades']);
            $aUpg = DataParser::toIntArray($town['aUpgrades']);
            if (($uUpg[$unitType] ?? 0) < 1 || ($wUpg[$unitType] ?? 0) < 1 || ($aUpg[$unitType] ?? 0) < 1) {
                $this->f3->set('SESSION.flash', $lang['unitNotResearched'] ?? 'Unit not researched. Upgrade HP, ATK, and DEF to level 1 first.');
                continue;
            }

            $resourceService = new ResourceService($this->db);

            // Calculate total cost
            $costParts = DataParser::toIntArray($unit['input']);
            $totalCost = DataParser::serialize(array_map(fn(int $c) => (string) ($c * $quantity), $costParts));

            if (!$resourceService->hasEnough($town['resources'], $totalCost)) {
                $this->f3->set('SESSION.flash', $lang['notEnoughRes'] ?? 'Not enough resources.');
                continue;
            }

            // Check weapon requirements
            $townService = $this->townService();
            $weapons = $townService->parseWeapons($town['weapons']);

            if (!empty($unit['requirements'])) {
                $reqs = explode('-', $unit['requirements']);
                $hasWeapons = true;
                foreach ($reqs as $wType) {
                    if (($weapons[(int) $wType] ?? 0) < $quantity) {
                        $hasWeapons = false;
                        break;
                    }
                }
                if (!$hasWeapons) {
                    $this->f3->set('SESSION.flash', $lang['notEnoughWeapons'] ?? 'Not enough weapons.');
                    continue;
                }

                // Deduct weapons
                foreach ($reqs as $wType) {
                    $weapons[(int) $wType] -= $quantity;
                }
                $this->db->exec(
                    'UPDATE towns SET weapons = ? WHERE id = ?',
                    [DataParser::serialize($weapons), $townId]
                );
            }

            $resourceService->deductResources($townId, $totalCost);

            $armyService = new \Devana\Services\ArmyService($this->db);
            $armyService->queueTraining($townId, $unitType, $quantity, $unit['duration']);
            $trained++;
        }

        if ($trained === 0 && !$this->f3->exists('SESSION.flash')) {
            $this->f3->set('SESSION.flash', $lang['noUnitsSelected'] ?? 'No units selected.');
        }
    }

    private function handleForge(int $townId, int $type, int $factionId): void
    {
        $this->loadLanguage();
        $lang = $this->f3->get('lang');

        // Template sends qty[weaponId] = amount for each weapon type
        $qtyArray = $this->post('qty', []);
        if (!is_array($qtyArray)) {
            return;
        }

        $allowedWeapons = match ($type) {
            18 => self::WAR_SHOP_WEAPONS,
            19 => self::STABLE_WEAPONS,
            default => [],
        };

        $forged = 0;
        foreach ($qtyArray as $weaponType => $rawQty) {
            $weaponType = (int) $weaponType;
            $quantity = max(0, (int) $rawQty);
            if ($quantity === 0) {
                continue;
            }

            if (!in_array($weaponType, $allowedWeapons, true)) {
                continue;
            }

            $weapon = $this->db->exec(
                'SELECT * FROM weapons WHERE type = ? AND faction = ?',
                [$weaponType, $factionId]
            );
            if (empty($weapon)) {
                continue;
            }
            $weapon = $weapon[0];

            // Reload town each iteration
            $town = $this->townService()->findById($townId);
            $resourceService = new ResourceService($this->db);

            $costParts = DataParser::toIntArray($weapon['input']);
            $totalCost = DataParser::serialize(array_map(fn(int $c) => (string) ($c * $quantity), $costParts));

            if (!$resourceService->hasEnough($town['resources'], $totalCost)) {
                $this->f3->set('SESSION.flash', $lang['notEnoughRes'] ?? 'Not enough resources.');
                continue;
            }

            $resourceService->deductResources($townId, $totalCost);

            $armyService = new \Devana\Services\ArmyService($this->db);
            $armyService->queueWeapon($townId, $weaponType, $quantity, $weapon['duration']);
            $forged++;
        }

        if ($forged === 0 && !$this->f3->exists('SESSION.flash')) {
            $this->f3->set('SESSION.flash', $lang['noWeaponsSelected'] ?? 'No weapons selected.');
        }
    }

    /**
     * Demolish a building or land improvement (reduce level by 1).
     * POST /town/@id/demolish
     */
    public function demolish(\Base $f3, array $params): void
    {
        $townId = (int) ($params['id'] ?? 0);

        if (!$this->requireCsrf('/town/' . $townId)) return;

        if (!$this->requireTownOwnership($townId)) return;
        $this->loadLanguage();
        $lang = $this->f3->get('lang');

        $user = $this->currentUser();

        $area = (int) $this->post('a', 0);  // 0-3 = land types, 4 = buildings
        $slot = (int) $this->post('b', 0);   // building type or sub-field index

        // Cannot demolish Hall (b7)
        if ($area === 4 && $slot === 7) {
            $this->f3->set('SESSION.flash_type', 'error');
            $this->flashAndRedirect($lang['cannotDemolishHall'] ?? 'Hall cannot be demolished.', '/town/' . $townId . '/building/7');
            return;
        }

        $townService = $this->townService();
        $town = $townService->findById($townId);
        $buildingService = new BuildingService($this->db);
        $buildings = $buildingService->getByFaction($user['faction']);
        $buildingsByType = $this->indexBuildingsByType($buildings);
        $lim = DataParser::toIntArray($town['limits']);
        $land = $townService->parseLand($town['land']);
        $buildingLevels = $townService->parseBuildingLevels($town['buildings']);

        if ($area < 4) {
            // Land demolish
            if (($land[$area][$slot] ?? 0) <= 0) {
                $this->f3->set('SESSION.flash_type', 'error');
                $this->flashAndRedirect($lang['nothingToDemolish'] ?? 'Nothing to demolish.', '/town/' . $townId . '/building/7');
                return;
            }

            $land[$area][$slot]--;

            // Recalculate production for this land type
            $output = explode('-', (string) ($buildingsByType[$area]['output'] ?? ''));
            $totalProd = 0;
            foreach ($land[$area] as $level) {
                if ($level > 0 && isset($output[$level - 1])) {
                    $totalProd += (int) $output[$level - 1];
                }
            }

            $prod = DataParser::toIntArray($town['production']);
            $prod[$area] = $totalProd;

            $landStr = implode('/', array_map(
                fn(array $a): string => DataParser::serialize($a),
                $land
            ));

            $this->db->exec(
                'UPDATE towns SET land = ?, production = ?, `limits` = ? WHERE id = ?',
                [$landStr, DataParser::serialize($prod), DataParser::serialize($lim), $townId]
            );
        } else {
            // Main building demolish
            if ($buildingLevels[$slot] <= 0) {
                $this->f3->set('SESSION.flash_type', 'error');
                $this->flashAndRedirect($lang['nothingToDemolish'] ?? 'Nothing to demolish.', '/town/' . $townId . '/building/7');
                return;
            }

            $buildingLevels[$slot]--;
            $newLevel = $buildingLevels[$slot];

            // Recalculate limits based on new level
            $limitMap = [
                4 => 0, 5 => 1, 6 => 5, 7 => 4, 8 => 3,
                13 => 6, 14 => 7, 15 => 8,
                18 => 9, 19 => 10, 20 => 11, 21 => 12,
            ];

            if (isset($limitMap[$slot])) {
                $output = explode('-', (string) ($buildingsByType[$slot]['output'] ?? ''));
                if ($newLevel > 0 && isset($output[$newLevel - 1])) {
                    $lim[$limitMap[$slot]] = (int) $output[$newLevel - 1];
                } else {
                    // Default limits when demolished to 0
                    $defaults = [0 => 600, 1 => 400, 2 => 200, 3 => 20, 4 => 100, 5 => 0];
                    $lim[$limitMap[$slot]] = $defaults[$limitMap[$slot]] ?? 0;
                }
            }

            // Hall special: reduce gold limit
            if ($slot === 7) {
                $lim[2] = max(0, ((int) ($lim[2] ?? 0)) - 800);
            }

            $this->db->exec(
                'UPDATE towns SET buildings = ?, `limits` = ? WHERE id = ?',
                [DataParser::serialize($buildingLevels), DataParser::serialize($lim), $townId]
            );
        }

        $townService->recalculatePopulation($townId, $user['faction']);
        (new \Devana\Services\UserService($this->db))->recalculatePoints((int) $user['id']);

        $this->f3->set('SESSION.flash_type', 'success');
        $this->flashAndRedirect($lang['buildingDemolished'] ?? 'Building demolished.', '/town/' . $townId . '/building/7');
    }

    /**
     * Set tax rate (gold production).
     * POST /town/@id/taxes
     */
    public function setTaxes(\Base $f3, array $params): void
    {
        $townId = (int) ($params['id'] ?? 0);

        if (!$this->requireCsrf('/town/' . $townId)) return;

        if (!$this->requireTownOwnership($townId)) return;

        $user = $this->currentUser();

        $taxes = abs((int) $this->post('taxes', 0));
        if ($taxes > 210) {
            $this->flashAndRedirect('Tax rate too high.', '/town/' . $townId . '/building/7');
            return;
        }

        $townService = $this->townService();
        $town = $townService->findById($townId);
        $prod = DataParser::toIntArray($town['production']);
        $buildingLevels = $townService->parseBuildingLevels($town['buildings']);

        // Cathedral bonus (building 11)
        $buildingService = new BuildingService($this->db);
        $buildings = $buildingService->getByFaction($user['faction']);
        $buildingsByType = $this->indexBuildingsByType($buildings);
        $cathedralOutput = explode('-', (string) ($buildingsByType[11]['output'] ?? ''));
        $cathedralLevel = $buildingLevels[11] ?? 0;
        $bonus = ($cathedralLevel > 0 && isset($cathedralOutput[$cathedralLevel - 1]))
            ? (int) $cathedralOutput[$cathedralLevel - 1]
            : 0;

        // Update gold production and morale
        $prod[4] = $taxes;
        $morale = 100 + $bonus - $taxes;

        $this->db->exec(
            'UPDATE towns SET production = ?, morale = ? WHERE id = ?',
            [DataParser::serialize($prod), max(0, $morale), $townId]
        );

        $this->flashAndRedirect('Taxes updated.', '/town/' . $townId . '/building/7');
    }

    /**
     * Initiate a unit upgrade (HP/ATK/DEF tree).
     * POST /town/@id/upgrade-unit
     */
    public function upgradeUnit(\Base $f3, array $params): void
    {
        $townId = (int) ($params['id'] ?? 0);

        if (!$this->requireCsrf('/town/' . $townId)) return;

        $this->loadLanguage();
        $lang = $this->f3->get('lang');

        if (!$this->requireTownOwnership($townId)) return;

        $user = $this->currentUser();

        $unitType = (int) $this->post('unit', 0);
        $tree = (int) $this->post('tree', 17);

        if (!in_array($tree, [17, 18, 19], true)) {
            $this->flashAndRedirect('Invalid upgrade tree.', '/town/' . $townId);
            return;
        }

        // Get unit data
        $unit = $this->db->exec(
            'SELECT input, duration FROM units WHERE type = ? AND faction = ?',
            [$unitType, $user['faction']]
        );
        if (empty($unit)) {
            $this->flashAndRedirect('Unit not found.', '/town/' . $townId);
            return;
        }
        $unit = $unit[0];

        $townService = $this->townService();
        $town = $townService->findById($townId);
        $col = QueueService::UPGRADE_TREE_MAP[$tree];
        $upgrades = DataParser::toIntArray($town[$col]);
        $currentLevel = $upgrades[$unitType] ?? 0;

        // Check max level
        if ($currentLevel >= 10) {
            $this->flashAndRedirect('Already at max level.', '/town/' . $townId);
            return;
        }

        // Check if already upgrading this unit
        $queueService = new QueueService($this->db);
        $upgradeStatus = $queueService->getUpgradeStatus($townId);
        if (isset($upgradeStatus[$unitType])) {
            $this->flashAndRedirect('Unit already being upgraded.', '/town/' . $townId);
            return;
        }

        // Calculate cost: base_cost * (currentLevel + 1)
        $costParts = DataParser::toFloatArray($unit['input']);
        $totalCost = DataParser::serialize(array_map(
            fn(float $c): string => (string) floor($c * ($currentLevel + 1)),
            $costParts
        ));

        // Check resources
        $resourceService = new ResourceService($this->db);
        if (!$resourceService->hasEnough($town['resources'], $totalCost)) {
            $this->flashAndRedirect($lang['notEnoughRes'] ?? 'Not enough resources.', '/town/' . $townId);
            return;
        }

        // Deduct and queue
        $resourceService->deductResources($townId, $totalCost);
        $queueService->addUpgrade($townId, $unitType, $tree, $unit['duration']);

        // Redirect back to the building page
        $buildingType = ($tree === 17) ? 16 : 17; // Academy or Blacksmith
        $this->redirect('/town/' . $townId . '/building/' . $buildingType);
    }

    private function resolveTemplate(int $type): string
    {
        return match (true) {
            $type <= 3 => 'building/resource-building.html',
            $type <= 6, $type === 21 => 'building/storage-building.html',
            $type === 7 => 'building/hall-building.html',
            $type === 16, $type === 17 => 'building/upgrade-building.html',
            in_array($type, [12, 15, 18, 19, 20], true) => 'building/military-building.html',
            default => 'building/special-building.html',
        };
    }

    /**
     * Return JSON data for the building quick-action popup.
     * GET /town/@id/building/@type/quick
     */
    public function quickInfo(\Base $f3, array $params): void
    {
        $townId = (int) ($params['id'] ?? 0);
        $type   = (int) ($params['type'] ?? 0);
        $user   = $this->currentUser();

        if ($user === null) {
            $this->jsonResponse(['ok' => false, 'error' => 'Unauthorized'], 401);
            return;
        }

        try {
            $townService = $this->townService();
            $town = $townService->findById($townId);
            if ($town === null || (int) $town['owner'] !== (int) $user['id']) {
                $this->jsonResponse(['ok' => false, 'error' => 'Not found'], 404);
                return;
            }

            $buildingService = new BuildingService($this->db);
            $building = $buildingService->getByTypeAndFaction($type, $user['faction']);
            if ($building === null) {
                $this->jsonResponse(['ok' => false, 'error' => 'Building not found'], 404);
                return;
            }

            $this->loadLanguage();
            $lang    = $this->f3->get('lang');
            $faction = $user['faction'];
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

            $building['name'] = $this->langCatalogText($lang, 'buildings', (int) $faction, $type, 0, (string) ($building['name'] ?? ''));
            $building['description'] = $this->langCatalogText($lang, 'buildings', (int) $faction, $type, 1, (string) ($building['description'] ?? ''));
            $building['name'] = $resolveBuildingName($type, (array) $lang, (int) $faction, (string) ($building['name'] ?? ''));

            $buildingLevels = $townService->parseBuildingLevels((string) ($town['buildings'] ?? ''));
            $resources      = $townService->parseResources((string) ($town['resources'] ?? '0-0-0-0-0'));
            $currentLevel   = (int) ($buildingLevels[$type] ?? 0);

            $category = match (true) {
                $type <= 3                                    => 'resource',
                $type <= 6 || $type === 21                   => 'storage',
                $type === 7                                   => 'hall',
                $type === 16 || $type === 17                 => 'upgrade',
                in_array($type, [12, 15, 18, 19, 20], true) => 'military',
                default                                       => 'special',
            };

            $canTrain        = in_array($type, [12, 15, 20], true);
            $canForge        = in_array($type, [18, 19], true);
            $canUpgradeUnits = ($type === 16 || $type === 17) && $currentLevel > 0;

            $durationRaw = (string) ($building['duration'] ?? '');
            $durations   = $durationRaw !== '' ? explode('-', $durationRaw) : [];
            if (count($durations) === 0) {
                $durations = ['0:00'];
            }
            $maxLevel  = count($durations);
            $inputRaw  = (string) ($building['input'] ?? '0-0-0-0-0');
            $costParts = DataParser::toIntArray($inputRaw);
            $upgradeCost = [
                'crop'   => $costParts[0] ?? 0,
                'lumber' => $costParts[1] ?? 0,
                'stone'  => $costParts[2] ?? 0,
                'iron'   => $costParts[3] ?? 0,
                'gold'   => $costParts[4] ?? 0,
                'time'   => $durations[$currentLevel] ?? '0:00',
            ];

            $canUpgrade = $currentLevel < $maxLevel;
            if ($canUpgrade) {
                foreach (['crop', 'lumber', 'stone', 'iron', 'gold'] as $i => $key) {
                    if (($resources[$key] ?? 0) < ($costParts[$i] ?? 0)) {
                        $canUpgrade = false;
                        break;
                    }
                }
            }

            $queueService  = new QueueService($this->db);
            $alreadyQueued = $queueService->isConstructionQueued($townId, $type, -1);

            $trainableUnits   = [];
            $forgeableWeapons = [];

            if ($canTrain || $canForge || $canUpgradeUnits) {
                $units   = $this->db->exec('SELECT * FROM units WHERE faction = ? ORDER BY type', [$faction]);
                $weapons = $this->db->exec('SELECT * FROM weapons WHERE faction = ? ORDER BY type', [$faction]);

                foreach ($units as &$u) {
                    $uT = (int) $u['type'];
                    $u['name'] = $this->langCatalogText($lang, 'units', (int) $faction, $uT, 0, (string) $u['name']);
                }
                unset($u);
                foreach ($weapons as &$w) {
                    $wT = (int) $w['type'];
                    $w['name'] = $this->langCatalogText($lang, 'weapons', (int) $faction, $wT, 0, (string) $w['name']);
                }
                unset($w);

                if ($canTrain) {
                    $allowedUnits = match ($type) {
                        15 => self::BARRACKS_UNITS,
                        20 => self::SIEGE_UNITS,
                        12 => self::PORT_UNITS,
                        default => [],
                    };
                    $uUpg = DataParser::toIntArray((string) ($town['uUpgrades'] ?? '0'));
                    $wUpg = DataParser::toIntArray((string) ($town['wUpgrades'] ?? '0'));
                    $aUpg = DataParser::toIntArray((string) ($town['aUpgrades'] ?? '0'));

                    foreach ($units as $u) {
                        $uId = (int) $u['type'];
                        if (!in_array($uId, $allowedUnits, true)) {
                            continue;
                        }
                        $c = DataParser::toIntArray((string) ($u['input'] ?? '0-0-0-0-0'));
                        $trainableUnits[] = [
                            'id'         => $uId,
                            'name'       => $u['name'],
                            'attack'     => (int) $u['attack'],
                            'defense'    => (int) $u['defense'],
                            'cost_crop'  => $c[0] ?? 0,
                            'cost_lumber'=> $c[1] ?? 0,
                            'cost_iron'  => $c[3] ?? 0,
                            'time'       => (string) ($u['duration'] ?? '0:00'),
                            'researched' => ($uUpg[$uId] ?? 0) >= 1
                                         && ($wUpg[$uId] ?? 0) >= 1
                                         && ($aUpg[$uId] ?? 0) >= 1,
                        ];
                    }
                }

                if ($canForge) {
                    $allowedWeapons = match ($type) {
                        18 => self::WAR_SHOP_WEAPONS,
                        19 => self::STABLE_WEAPONS,
                        default => [],
                    };
                    foreach ($weapons as $w) {
                        $wId = (int) $w['type'];
                        if (!in_array($wId, $allowedWeapons, true)) {
                            continue;
                        }
                        $c = DataParser::toIntArray((string) ($w['input'] ?? '0-0-0-0-0'));
                        $forgeableWeapons[] = [
                            'id'        => $wId,
                            'name'      => $w['name'],
                            'cost_crop' => $c[0] ?? 0,
                            'cost_iron' => $c[3] ?? 0,
                            'cost_gold' => $c[4] ?? 0,
                            'time'      => (string) ($w['duration'] ?? '0:00'),
                        ];
                    }
                }
            }

            $this->jsonResponse([
                'ok' => true,
                'building' => [
                    'type'        => $type,
                    'name'        => (string) ($building['name'] ?? ('Building #' . $type)),
                    'description' => (string) ($building['description'] ?? ''),
                    'level'       => $currentLevel,
                    'max_level'   => max(1, $maxLevel),
                    'category'    => $category,
                ],
                'upgrade' => [
                    'can_upgrade'    => $canUpgrade,
                    'already_queued' => $alreadyQueued,
                    'at_max'         => $currentLevel >= $maxLevel,
                    'cost'           => $upgradeCost,
                ],
                'trainable_units'   => $trainableUnits,
                'forgeable_weapons' => $forgeableWeapons,
                'can_train'         => $canTrain,
                'can_forge'         => $canForge,
                'can_upgrade_units' => $canUpgradeUnits,
                'resources'         => $resources,
            ]);
        } catch (\Throwable $e) {
            $this->jsonResponse([
                'ok' => true,
                'building' => [
                    'type' => $type,
                    'name' => 'Building #' . $type,
                    'description' => '',
                    'level' => 0,
                    'max_level' => 1,
                    'category' => 'special',
                ],
                'upgrade' => [
                    'can_upgrade' => false,
                    'already_queued' => false,
                    'at_max' => true,
                    'cost' => ['crop' => 0, 'lumber' => 0, 'stone' => 0, 'iron' => 0, 'gold' => 0, 'time' => '0:00'],
                ],
                'trainable_units' => [],
                'forgeable_weapons' => [],
                'can_train' => false,
                'can_forge' => false,
                'can_upgrade_units' => false,
                'resources' => ['crop' => 0, 'lumber' => 0, 'stone' => 0, 'iron' => 0, 'gold' => 0],
            ]);
        }
    }

    private function jsonResponse(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data);
        // Prevent F3 from rendering a template
        $this->f3->abort();
    }
}

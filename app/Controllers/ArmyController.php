<?php declare(strict_types=1);

namespace Devana\Controllers;

use Devana\Helpers\DataParser;
use Devana\Helpers\InputSanitizer;
use Devana\Services\ArmyService;
use Devana\Services\BarbarianService;
use Devana\Services\MapService;
use Devana\Services\MissionService;
use Devana\Services\ResourceService;
use Devana\Services\QueueService;
use Devana\Services\BattleService;

final class ArmyController extends Controller
{
    public function showDispatch(\Base $f3, array $params): void
    {
        $townId = (int) ($params['id'] ?? 0);

        $town = $this->requireOwnedTown($townId);
        if ($town === null) return;

        $user = $this->currentUser();
        $this->loadLanguage();
        $lang = $this->f3->get('lang');
        $faction = $user['faction'];

        $armyService = new ArmyService($this->db);
        $units = $armyService->getUnitsByFaction($user['faction']);
        $targetName = InputSanitizer::clean((string) $this->get('target', ''));
        $targetX = InputSanitizer::cleanInt($this->get('target_x', 0));
        $targetY = InputSanitizer::cleanInt($this->get('target_y', 0));
        $targetCampId = InputSanitizer::cleanInt($this->get('target_camp_id', 0));

        // Map context menu may send target town id as ?target=123.
        if ($targetName !== '' && ctype_digit($targetName)) {
            $targetTown = $this->db->exec(
                'SELECT t.name, m.x, m.y FROM towns t LEFT JOIN map m ON m.type = 3 AND m.subtype = t.id WHERE t.id = ?',
                [(int) $targetName]
            );
            if (!empty($targetTown)) {
                $targetName = (string) ($targetTown[0]['name'] ?? '');
                if ($targetX <= 0) {
                    $targetX = (int) ($targetTown[0]['x'] ?? 0);
                }
                if ($targetY <= 0) {
                    $targetY = (int) ($targetTown[0]['y'] ?? 0);
                }
            }
        }

        // Build army with translated unit names for template
        $armyRaw = $this->townService()->parseArmy($town['army']);
        $army = [];
        foreach ($armyRaw as $i => $count) {
            if (isset($units[$i])) {
                $unitName = $lang['units'][$faction][$i][0] ?? $units[$i]['name'];
                $army[] = ['id' => $i, 'name' => $unitName, 'count' => $count];
            }
        }

        // Camp prefill data
        $campPrefill = null;
        if ($targetCampId > 0) {
            $campRow = $this->db->exec(
                'SELECT id, x, y, level FROM barbarian_camps WHERE id = ? AND active = 1 LIMIT 1',
                [$targetCampId]
            );
            if (!empty($campRow)) {
                $campPrefill = $campRow[0];
            }
        }

        $this->render('army/dispatch.html', [
            'page_title' => $f3->get('lang.crossroad') ?? 'Dispatch',
            'town' => $town,
            'army' => $army,
            'general' => DataParser::toIntArray($town['general']),
            'units' => $units,
            'resources' => $this->townService()->parseResources($town['resources']),
            'limits' => $this->townService()->parseLimits($town['limits']),
            'target_name_prefill' => $targetName,
            'target_x_prefill' => $targetX,
            'target_y_prefill' => $targetY,
            'target_camp_prefill' => $campPrefill,
        ]);
    }

    public function dispatch(\Base $f3, array $params): void
    {
        $townId = (int) ($params['id'] ?? 0);
        $user = $this->currentUser();
        $this->loadLanguage();
        $lang = $this->f3->get('lang');

        if (!$this->requireCsrf('/town/' . $townId . '/dispatch')) return;

        if (!$this->requireTownOwnership($townId)) return;

        $targetName = InputSanitizer::clean($this->post('target', ''));
        $targetX = InputSanitizer::cleanInt($this->post('target_x', 0));
        $targetY = InputSanitizer::cleanInt($this->post('target_y', 0));
        $actionStr = $this->post('action', 'reinforce');
        $actionMap = ['reinforce' => 0, 'raid' => 1, 'attack' => 2, 'spy' => 3];
        $actionType = $actionMap[$actionStr] ?? InputSanitizer::cleanInt($this->post('type', 0));

        // ── Barbarian camp dispatch ──────────────────────────────────────
        $targetCampId = InputSanitizer::cleanInt($this->post('target_camp_id', 0));
        if ($targetCampId > 0) {
            $campRow = $this->db->exec(
                'SELECT * FROM barbarian_camps WHERE id = ? AND active = 1 LIMIT 1',
                [$targetCampId]
            );
            if (empty($campRow)) {
                $this->f3->set('SESSION.flash_type', 'error');
                $this->flashAndRedirect('Barbarian camp not found or already defeated.', '/town/' . $townId . '/dispatch');
                return;
            }
            $camp = $campRow[0];

            // Parse army to send
            $town       = $this->townService()->findById($townId);
            $currentArmy = $this->townService()->parseArmy($town['army']);
            $sendArmy   = [];
            $totalSending = 0;
            $unitsPost  = $this->post('units', []);
            for ($i = 0; $i < 13; $i++) {
                $raw   = (int) ($unitsPost[$i] ?? $this->post('u' . $i, 0));
                $count = max(0, min($raw, $currentArmy[$i]));
                $sendArmy[$i] = $count;
                $totalSending += $count;
            }
            if ($totalSending === 0) {
                $this->f3->set('SESSION.flash_type', 'error');
                $this->flashAndRedirect($lang['armyVoid'] ?? 'No units selected.', '/town/' . $townId . '/dispatch');
                return;
            }

            // Travel time using camp coordinates
            $mapService  = new MapService($this->db);
            $fromCoords  = $mapService->getTownLocation($townId);
            if ($fromCoords === null) {
                $this->f3->set('SESSION.flash_type', 'error');
                $this->flashAndRedirect($lang['mapError'] ?? 'Map error.', '/town/' . $townId . '/dispatch');
                return;
            }
            $armyService = new ArmyService($this->db);
            $units       = $armyService->getUnitsByFaction($user['faction']);
            $minSpeed    = PHP_INT_MAX;
            foreach ($sendArmy as $i => $cnt) {
                if ($cnt > 0 && isset($units[$i])) {
                    $minSpeed = min($minSpeed, (int) $units[$i]['speed']);
                }
            }
            $distance      = $mapService->calculateDistance(
                (int) $fromCoords['x'], (int) $fromCoords['y'],
                (int) $camp['x'], (int) $camp['y']
            );
            $travelSeconds = $mapService->calculateTravelTime($distance, $minSpeed);
            $general       = \Devana\Helpers\DataParser::toIntArray($town['general']);

            // Store with virtual target = CAMP_OFFSET + campId
            $virtualTarget = BarbarianService::CAMP_OFFSET + $targetCampId;
            $armyService->dispatch($townId, $virtualTarget, 1, $sendArmy, $general, $travelSeconds, [0, 0, 0, 0, 0]);

            // Mission trigger: RAID
            try {
                (new MissionService($this->db))->incrementProgress((int) $user['id'], MissionService::TYPE_RAID);
            } catch (\Throwable $e) {
                // non-critical
            }

            $this->f3->set('SESSION.flash_type', 'success');
            $this->flashAndRedirect($lang['armyDispatched'] ?? 'Army dispatched.', '/town/' . $townId);
            return;
        }
        // ─────────────────────────────────────────────────────────────────

        // Find target town by name or coordinates
        $target = [];
        if (!empty($targetName)) {
            $target = $this->db->exec('SELECT * FROM towns WHERE name = ?', [$targetName]);
        } elseif ($targetX > 0 || $targetY > 0) {
            $mapTile = $this->db->exec(
                'SELECT subtype FROM map WHERE x = ? AND y = ? AND type = 3',
                [$targetX, $targetY]
            );
            if (!empty($mapTile)) {
                $target = $this->db->exec('SELECT * FROM towns WHERE id = ?', [(int) $mapTile[0]['subtype']]);
            }
        }

        if (empty($target)) {
            $this->f3->set('SESSION.flash_type', 'error');
            $this->flashAndRedirect($lang['noTown'] ?? 'Target town not found.', '/town/' . $townId . '/dispatch');
            return;
        }

        $target = $target[0];

        // Beginner protection: block offensive actions against protected players.
        if (in_array($actionType, [1, 2, 3], true) && (int) ($target['owner'] ?? 0) > 0 && (int) $target['owner'] !== (int) $user['id']) {
            $protectionHours = max(0, (int) ($f3->get('game.beginner_protection') ?? 72));
            if ($protectionHours > 0) {
                $protected = $this->db->exec(
                    'SELECT 1 FROM users WHERE id = ? AND DATE_ADD(joined, INTERVAL ? HOUR) > NOW() LIMIT 1',
                    [(int) $target['owner'], $protectionHours]
                );
                if (!empty($protected)) {
                    $this->f3->set('SESSION.flash_type', 'error');
                    $this->flashAndRedirect('Target player is under beginner protection.', '/town/' . $townId . '/dispatch');
                    return;
                }
            }
        }

        // Parse army to send
        $town = $this->townService()->findById($townId);
        $currentArmy = $this->townService()->parseArmy($town['army']);
        $sendArmy = [];
        $totalSending = 0;

        $unitsPost = $this->post('units', []);
        for ($i = 0; $i < 13; $i++) {
            $raw = (int) ($unitsPost[$i] ?? $this->post('u' . $i, 0));
            $count = max(0, min($raw, $currentArmy[$i]));
            $sendArmy[$i] = $count;
            $totalSending += $count;
        }

        if ($totalSending === 0) {
            $this->f3->set('SESSION.flash_type', 'error');
            $this->flashAndRedirect($lang['armyVoid'] ?? 'No units selected.', '/town/' . $townId . '/dispatch');
            return;
        }

        // Calculate travel time
        $mapService = new MapService($this->db);
        $fromCoords = $mapService->getTownLocation($townId);
        $toCoords = $mapService->getTownLocation((int) $target['id']);

        if ($fromCoords === null || $toCoords === null) {
            $this->f3->set('SESSION.flash_type', 'error');
            $this->flashAndRedirect($lang['mapError'] ?? 'Map error.', '/town/' . $townId . '/dispatch');
            return;
        }

        // Slowest unit determines speed
        $armyService = new ArmyService($this->db);
        $units = $armyService->getUnitsByFaction($user['faction']);
        $minSpeed = PHP_INT_MAX;

        foreach ($sendArmy as $i => $count) {
            if ($count > 0 && isset($units[$i])) {
                $minSpeed = min($minSpeed, (int) $units[$i]['speed']);
            }
        }

        $distance = $mapService->calculateDistance(
            (int) $fromCoords['x'], (int) $fromCoords['y'],
            (int) $toCoords['x'], (int) $toCoords['y']
        );
        $travelSeconds = $mapService->calculateTravelTime($distance, $minSpeed);

        // Parse general from town data
        $general = DataParser::toIntArray($town['general']);

        // Parse requested resources to send
        $requestedRes = [
            max(0, InputSanitizer::cleanInt($this->post('res_crop', 0))),
            max(0, InputSanitizer::cleanInt($this->post('res_lumber', 0))),
            max(0, InputSanitizer::cleanInt($this->post('res_stone', 0))),
            max(0, InputSanitizer::cleanInt($this->post('res_iron', 0))),
            0, // gold is not sent
        ];
        $totalRequested = array_sum($requestedRes);
        $carryCapacity = $totalSending * 10;
        if ($totalRequested > $carryCapacity) {
            $this->f3->set('SESSION.flash_type', 'error');
            $this->flashAndRedirect('Not enough carrying capacity for selected resources.', '/town/' . $townId . '/dispatch');
            return;
        }

        // Deduct resources from sender town atomically (prevents double-send race condition)
        $sendRes = [0, 0, 0, 0, 0];
        if ($totalRequested > 0) {
            $this->db->begin();
            try {
                $freshRow = $this->db->exec('SELECT resources FROM towns WHERE id = ? FOR UPDATE', [$townId]);
                $freshRes = $this->townService()->parseResources($freshRow[0]['resources']);
                $sendRes = [
                    max(0, min((int) $freshRes['crop'],   $requestedRes[0])),
                    max(0, min((int) $freshRes['lumber'],  $requestedRes[1])),
                    max(0, min((int) $freshRes['stone'],   $requestedRes[2])),
                    max(0, min((int) $freshRes['iron'],    $requestedRes[3])),
                    0,
                ];
                $newRes = [
                    $freshRes['crop']   - $sendRes[0],
                    $freshRes['lumber'] - $sendRes[1],
                    $freshRes['stone']  - $sendRes[2],
                    $freshRes['iron']   - $sendRes[3],
                    $freshRes['gold'],
                ];
                $this->db->exec(
                    'UPDATE towns SET resources = ? WHERE id = ?',
                    [DataParser::serializeRounded($newRes), $townId]
                );
                $this->db->commit();
            } catch (\Throwable $e) {
                $this->db->rollback();
                $this->f3->set('SESSION.flash_type', 'error');
                $this->flashAndRedirect($lang['dispatchFailed'] ?? 'Dispatch failed, please try again.', '/town/' . $townId . '/dispatch');
                return;
            }
        }

        $armyService->dispatch($townId, (int) $target['id'], $actionType, $sendArmy, $general, $travelSeconds, $sendRes);

        // Mission trigger: RAID
        if ($actionType === 1) {
            try {
                (new MissionService($this->db))->incrementProgress((int) $user['id'], MissionService::TYPE_RAID);
            } catch (\Throwable $e) {
                // non-critical
            }
        }

        $this->f3->set('SESSION.flash_type', 'success');
        $this->flashAndRedirect($lang['armyDispatched'] ?? 'Army dispatched.', '/town/' . $townId);
    }

    public function showTrain(\Base $f3, array $params): void
    {
        $this->redirect('/town/' . ($params['id'] ?? 0) . '/building/15');
    }

    public function train(\Base $f3, array $params): void
    {
        $this->redirect('/town/' . ($params['id'] ?? 0) . '/building/15');
    }

    public function showGeneral(\Base $f3, array $params): void
    {
        $townId = (int) ($params['id'] ?? 0);

        $town = $this->requireOwnedTown($townId);
        if ($town === null) return;

        $user = $this->currentUser();
        $this->loadLanguage();
        $lang = $this->f3->get('lang');
        $faction = $user['faction'];

        $general = DataParser::parseGeneral($town['general']);
        $hasGeneral = $general['presence'] > 0;

        $formationNames = [0 => 'Standard', 1 => 'Offensive', 2 => 'Defensive'];

        $armyService = new ArmyService($this->db);
        $units = $armyService->getUnitsByFaction($user['faction']);
        $dbName = $units[$general['unit_type']]['name'] ?? 'Unknown';
        $unitName = $lang['units'][$faction][$general['unit_type']][0] ?? $dbName;

        $nextLevelXp = (int) (pow($general['level'] + 1, 2) * 50);

        $generalData = [
            'unit_name' => $unitName,
            'unit_type' => $general['unit_type'],
            'level' => $general['level'],
            'xp' => $general['xp'],
            'next_level_xp' => $nextLevelXp,
            'formation' => $general['formation'],
            'formation_name' => $formationNames[$general['formation']] ?? 'Standard',
        ];

        // Build available units for promotion (units with count > 0)
        $armyRaw = $this->townService()->parseArmy($town['army']);
        $availableUnits = [];
        foreach ($armyRaw as $i => $count) {
            if ($count > 0 && isset($units[$i])) {
                $uName = $lang['units'][$faction][$i][0] ?? $units[$i]['name'];
                $availableUnits[] = ['id' => $i, 'name' => $uName, 'count' => $count];
            }
        }

        $this->render('army/general.html', [
            'page_title' => $f3->get('lang.general') ?? 'General',
            'town' => $town,
            'has_general' => $hasGeneral,
            'general' => $generalData,
            'available_units' => $availableUnits,
            'army' => $armyRaw,
            'resources' => $this->townService()->parseResources($town['resources']),
            'limits' => $this->townService()->parseLimits($town['limits']),
        ]);
    }

    public function setGeneral(\Base $f3, array $params): void
    {
        $townId = (int) ($params['id'] ?? 0);
        $user = $this->currentUser();

        if (!$this->requireCsrf('/town/' . $townId . '/general')) return;

        if (!$this->requireTownOwnership($townId)) return;

        $action = $this->post('action', '');
        $town = $this->townService()->findById($townId);
        $generalParts = DataParser::toIntArray($town['general']);

        if ($action === 'formation') {
            // Change formation only
            $formation = max(0, min(2, InputSanitizer::cleanInt($this->post('formation', 0))));
            $generalParts[3] = $formation;
            // Ensure 5 elements
            while (count($generalParts) < 5) {
                $generalParts[] = 0;
            }
            $this->db->exec(
                'UPDATE towns SET general = ? WHERE id = ?',
                [DataParser::serialize($generalParts), $townId]
            );
            $this->flashAndRedirect('Formation updated.', '/town/' . $townId . '/general');
        } elseif ($action === 'promote') {
            // Promote a unit to general
            $unitType = InputSanitizer::cleanInt($this->post('unit_type', 0));
            $army = $this->townService()->parseArmy($town['army']);

            if (!isset($army[$unitType]) || $army[$unitType] < 1) {
                $this->flashAndRedirect('Not enough units.', '/town/' . $townId . '/general');
                return;
            }

            // Remove 1 unit from army
            $army[$unitType]--;
            $this->db->exec(
                'UPDATE towns SET army = ?, upkeep = ? WHERE id = ?',
                [DataParser::serialize($army), array_sum($army), $townId]
            );

            // Set new general: presence=1, level=1, unit_type, formation=current or 0, xp=0
            $currentFormation = $generalParts[3] ?? 0;
            $newGeneral = '1-1-' . $unitType . '-' . $currentFormation . '-0';
            $this->db->exec(
                'UPDATE towns SET general = ? WHERE id = ?',
                [$newGeneral, $townId]
            );
            $this->flashAndRedirect('Unit promoted to general.', '/town/' . $townId . '/general');
        } else {
            $this->flashAndRedirect('Invalid action.', '/town/' . $townId . '/general');
        }
    }

    public function combatSimulator(\Base $f3, array $params): void
    {
        $townId = (int) ($params['id'] ?? 0);
        $user = $this->currentUser();

        // Get all unit types for the combat simulator form
        $units = $this->db->exec('SELECT type AS id, name FROM units WHERE faction = ? ORDER BY type', [$user['faction']]);

        $data = [
            'page_title' => 'Combat Simulator',
            'town' => ['id' => $townId],
            'town_id' => $townId,
            'unit_types' => $units,
        ];

        // Process simulation if POST
        if ($this->post('simulate') !== null) {
            $attArmy = [];
            $defArmy = [];
            $attPost = $this->post('att', []);
            $defPost = $this->post('def', []);

            for ($i = 0; $i < 13; $i++) {
                $attArmy[$i] = max(0, (int) ($attPost[$i] ?? 0));
                $defArmy[$i] = max(0, (int) ($defPost[$i] ?? 0));
            }

            $battleService = new BattleService($this->db);
            $simResult = $battleService->simulateBattle($attArmy, $defArmy, $user['faction']);

            // Build result arrays for template
            $attackerLosses = [];
            $defenderLosses = [];
            foreach ($units as $u) {
                $uid = (int) $u['id'];
                $atkBefore = $simResult['atkBefore'][$uid] ?? 0;
                $atkAfter = $simResult['atkArmy'][$uid] ?? 0;
                $defBefore = $simResult['defBefore'][$uid] ?? 0;
                $defAfter = $simResult['defArmy'][$uid] ?? 0;

                $attackerLosses[$uid] = [
                    'name' => $u['name'],
                    'total' => $atkBefore,
                    'lost' => max(0, $atkBefore - $atkAfter),
                ];
                $defenderLosses[$uid] = [
                    'name' => $u['name'],
                    'total' => $defBefore,
                    'lost' => max(0, $defBefore - $defAfter),
                ];
            }

            $data['result'] = [
                'attacker_losses' => $attackerLosses,
                'defender_losses' => $defenderLosses,
                'winner' => $simResult['attackerWins'] ? 'Attacker' : 'Defender',
            ];
        }

        $this->render('army/combat-simulator.html', $data);
    }

}

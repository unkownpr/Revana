<?php

declare(strict_types=1);

namespace Devana\Services;

use Devana\Services\AchievementService;
use Devana\Services\AllianceWarService;
use Devana\Services\MissionService;
use Devana\Services\NotificationService;
use Devana\Services\SeasonService;
use Devana\Services\WeeklyMissionService;

final class BattleService
{
    private \DB\SQL $db;

    public function __construct(\DB\SQL $db)
    {
        $this->db = $db;
    }

    /**
     * Process an army arrival at target. Called from QueueService::processArmyQueue().
     *
     * @return array{army: string, rLoot: string, wLoot: string, intel: string}
     */
    public function processArrival(array $movement): array
    {
        $type = (int) $movement['type'];
        $targetId = (int) $movement['target'];
        $fromId = (int) $movement['town'];

        $target = $this->db->exec('SELECT * FROM towns WHERE id = ?', [$targetId]);
        $from = $this->db->exec('SELECT * FROM towns WHERE id = ?', [$fromId]);

        if (empty($target) || empty($from)) {
            return [
                'army' => $movement['army'],
                'rLoot' => '0-0-0-0-0',
                'wLoot' => '0-0-0-0-0-0-0-0-0-0-0',
            ];
        }

        $target = $target[0];
        $from = $from[0];

        // Get unit data for both factions
        $defFaction = (int) $target['owner'];
        $defUser = $this->db->exec('SELECT faction FROM users WHERE id = ?', [$defFaction]);
        $defFactionId = !empty($defUser) ? (int) $defUser[0]['faction'] : 1;

        $atkUser = $this->db->exec('SELECT faction FROM users WHERE id = ?', [(int) $from['owner']]);
        $atkFactionId = !empty($atkUser) ? (int) $atkUser[0]['faction'] : 1;

        $defUnits = $this->db->exec('SELECT * FROM units WHERE faction = ? ORDER BY type', [$defFactionId]);
        $atkUnits = $this->db->exec('SELECT * FROM units WHERE faction = ? ORDER BY type', [$atkFactionId]);

        // Build battle data
        $defArmy = array_map('intval', explode('-', $target['army']));
        $atkArmy = array_map('intval', explode('-', $movement['army']));
        $defGen = explode('-', $target['general']);
        $atkGen = explode('-', $movement['general']);
        $defBuildings = array_map('intval', explode('-', $target['buildings']));
        $defLimits = array_map('intval', explode('-', $target['limits']));
        $defResources = array_map('floatval', explode('-', $target['resources']));
        $defWeapons = array_map('intval', explode('-', $target['weapons']));
        $defUUpgrades = array_map('intval', explode('-', $target['uUpgrades']));
        $defWUpgrades = array_map('intval', explode('-', $target['wUpgrades']));
        $defAUpgrades = array_map('intval', explode('-', $target['aUpgrades']));

        // Attacker upgrades come with the army
        $atkUUpgrades = array_map('intval', explode('-', $movement['uup'] ?? $from['uUpgrades']));
        $atkWUpgrades = array_map('intval', explode('-', $movement['wup'] ?? $from['wUpgrades']));
        $atkAUpgrades = array_map('intval', explode('-', $movement['aup'] ?? $from['aUpgrades']));

        // Run battle
        $result = $this->battle(
            $defUnits, $atkUnits,
            $defArmy, $atkArmy,
            $defGen, $atkGen,
            $defLimits,
            $defResources, $defWeapons,
            $defUUpgrades, $defWUpgrades, $defAUpgrades,
            $atkUUpgrades, $atkWUpgrades, $atkAUpgrades,
            $defBuildings,
            $type
        );

        $this->db->begin();
        try {
            // Update defender's town
            $this->db->exec(
                'UPDATE towns SET army = ?, resources = ?, weapons = ?, buildings = ?, upkeep = ? WHERE id = ?',
                [
                    implode('-', $result['defArmy']),
                    implode('-', array_map(fn(float $v): string => (string) round($v, 2), $result['defResources'])),
                    implode('-', $result['defWeapons']),
                    implode('-', $result['defBuildings']),
                    array_sum($result['defArmy']),
                    $targetId,
                ]
            );

            // General XP gain for attacker on victory
            if ($result['attackerWins'] && (int) ($atkGen[0] ?? 0) > 0) {
                $atkGenLevel = (int) ($atkGen[1] ?? 1);
                $atkGenXp = (int) ($atkGen[4] ?? 0);

                // XP = 10 base + number of defender units killed
                $defBefore = array_map('intval', explode('-', $target['army']));
                $unitsKilled = 0;
                for ($i = 0; $i < count($defBefore); $i++) {
                    $unitsKilled += max(0, $defBefore[$i] - ($result['defArmy'][$i] ?? 0));
                }
                $xpGain = 10 + $unitsKilled;
                $atkGenXp += $xpGain;

                // Level formula: level = floor(sqrt(xp/50))
                $newLevel = max(1, (int) floor(sqrt($atkGenXp / 50)));
                if ($newLevel < 1) $newLevel = 1;

                // Update attacker's general in source town
                $newGenStr = ($atkGen[0] ?? '1') . '-' . $newLevel . '-' . ($atkGen[2] ?? '0') . '-' . ($atkGen[3] ?? '0') . '-' . $atkGenXp;
                $this->db->exec(
                    'UPDATE towns SET general = ? WHERE id = ?',
                    [$newGenStr, $fromId]
                );
            }

            // Build intel for spy missions
            $intel = '';
            if ($type === 3 && $result['attackerWins']) {
                $scoutsSent = array_map('intval', explode('-', $movement['army']));
                $totalScouts = array_sum($scoutsSent);
                $intelData = [];

                // Always reveal army composition
                $intelData['army'] = implode('-', $result['defArmy']);

                // With 5+ scouts, reveal resources
                if ($totalScouts >= 5) {
                    $intelData['resources'] = implode('-', array_map(fn(float $v): string => (string) round($v), $result['defResources']));
                }

                // With 10+ scouts, reveal building levels
                if ($totalScouts >= 10) {
                    $intelData['buildings'] = implode('-', $result['defBuildings']);
                }

                $intel = json_encode($intelData);
            }

            // Send battle reports to both players
            $this->sendBattleReport($from, $target, $atkArmy, $defArmy, $result, $type, $intel);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        // Post-commit: notifications, missions, season scores (non-critical)
        $attackerOwnerId = (int) ($from['owner'] ?? 0);
        $defenderOwnerId = (int) ($target['owner'] ?? 0);

        if ($attackerOwnerId > 0 && in_array($type, [1, 2], true)) {
            try {
                // Notify defender of attack arrival
                (new NotificationService($this->db))->notify($defenderOwnerId, NotificationService::TYPE_ATTACK_INCOMING, 'notifAttackArrived', '/town/' . $targetId);
            } catch (\Throwable $e) {
                // non-critical
            }

            if ($result['attackerWins']) {
                // Mission: raid
                try {
                    (new MissionService($this->db))->incrementProgress($attackerOwnerId, MissionService::TYPE_RAID);
                    (new WeeklyMissionService($this->db))->incrementProgress($attackerOwnerId, WeeklyMissionService::TYPE_RAID);
                    $this->db->exec('UPDATE users SET total_raids = total_raids + 1 WHERE id = ?', [$attackerOwnerId]);
                    $totalRow = $this->db->exec('SELECT total_raids FROM users WHERE id = ? LIMIT 1', [$attackerOwnerId]);
                    $total = (int) ($totalRow[0]['total_raids'] ?? 0);
                    $achService = new AchievementService($this->db);
                    $achService->check($attackerOwnerId, 'first_raid', $total);
                    $achService->check($attackerOwnerId, 'veteran_10_raids', $total);
                    $achService->check($attackerOwnerId, 'veteran_50_raids', $total);
                } catch (\Throwable $e) {
                    // non-critical
                }

                // Season score
                try {
                    $seasonScore = ($type === 1) ? SeasonService::SCORE_RAID_WIN : SeasonService::SCORE_ATTACK_WIN;
                    (new SeasonService($this->db))->addScore($attackerOwnerId, $seasonScore, $type === 1 ? 'raid_win' : 'attack_win');
                } catch (\Throwable $e) {
                    // non-critical
                }

                // Alliance war score
                try {
                    $atkAllianceRow = $this->db->exec('SELECT alliance FROM users WHERE id = ? LIMIT 1', [$attackerOwnerId]);
                    $defAllianceRow = $this->db->exec('SELECT alliance FROM users WHERE id = ? LIMIT 1', [$defenderOwnerId]);
                    $atkAllianceId = (int) ($atkAllianceRow[0]['alliance'] ?? 0);
                    $defAllianceId = (int) ($defAllianceRow[0]['alliance'] ?? 0);
                    if ($atkAllianceId > 0 && $defAllianceId > 0 && $atkAllianceId !== $defAllianceId) {
                        $warService = new AllianceWarService($this->db);
                        $war = $warService->getActiveWar($atkAllianceId);
                        if ($war !== null && (int) ($war['defender_id'] ?? 0) === $defAllianceId) {
                            $warPoints = ($type === 1) ? 5 : 15;
                            $warService->addScore((int) $war['id'], $attackerOwnerId, $atkAllianceId, $warPoints);
                        }
                    }
                } catch (\Throwable $e) {
                    // non-critical
                }
            }
        }

        return [
            'army' => implode('-', $result['atkArmy']),
            'rLoot' => implode('-', array_map(fn(float $v): string => (string) round($v), $result['rLoot'])),
            'wLoot' => implode('-', $result['wLoot']),
            'intel' => $intel ?? '',
        ];
    }

    /**
     * Simulate a battle for the combat simulator. Does not modify any DB state.
     *
     * @param array $attArmy  Array of attacker unit counts [type => count]
     * @param array $defArmy  Array of defender unit counts [type => count]
     * @param int   $factionId Faction ID for both sides (uses same unit stats)
     * @return array{attackerWins: bool, atkArmy: array, defArmy: array, atkBefore: array, defBefore: array}
     */
    public function simulateBattle(array $attArmy, array $defArmy, int $factionId): array
    {
        $units = $this->db->exec('SELECT * FROM units WHERE faction = ? ORDER BY type', [$factionId]);
        $zeroes = array_fill(0, 13, 0);
        $zeroUpgrades = array_fill(0, 13, 0);
        $defLimits = array_fill(0, 13, 0);
        $defResources = [0.0, 0.0, 0.0, 0.0, 0.0];
        $defWeapons = array_fill(0, 11, 0);
        $defBuildings = array_fill(0, 22, 0);

        $atkArmyBefore = $attArmy;
        $defArmyBefore = $defArmy;

        $result = $this->battle(
            $units, $units,
            $defArmy, $attArmy,
            [0, 0, 0, 0, 0], [0, 0, 0, 0, 0],
            $defLimits,
            $defResources, $defWeapons,
            $zeroUpgrades, $zeroUpgrades, $zeroUpgrades,
            $zeroUpgrades, $zeroUpgrades, $zeroUpgrades,
            $defBuildings,
            2 // Attack type
        );

        return [
            'attackerWins' => $result['attackerWins'],
            'atkArmy' => $result['atkArmy'],
            'defArmy' => $result['defArmy'],
            'atkBefore' => $atkArmyBefore,
            'defBefore' => $defArmyBefore,
            'units' => $units,
        ];
    }

    /**
     * Full battle calculation - port of legacy battle() function.
     */
    private function battle(
        array $defUnits, array $atkUnits,
        array $defArmy, array $atkArmy,
        array $defGen, array $atkGen,
        array $defLimits,
        array $defResources, array $defWeapons,
        array $defUUp, array $defWUp, array $defAUp,
        array $atkUUp, array $atkWUp, array $atkAUp,
        array $defBuildings,
        int $type
    ): array {
        $rLoot = [0.0, 0.0, 0.0, 0.0, 0.0];
        $wLoot = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];

        // Formation bonuses from limits[6] (def bonus) and limits[7] (atk bonus)
        $defBonus = ['atk' => (float) ($defLimits[7] ?? 0), 'def' => (float) ($defLimits[6] ?? 0)];
        $atkBonus = ['atk' => 0.0, 'def' => 0.0];

        // Formation modifiers (general[3]: 0=standard, 1=offensive, 2=defensive)
        $defFormation = (int) ($defGen[3] ?? 0);
        $atkFormation = (int) ($atkGen[3] ?? 0);

        if ($defFormation === 1) { $defBonus['atk'] *= 1.25; $defBonus['def'] *= 0.75; }
        elseif ($defFormation === 2) { $defBonus['atk'] *= 0.75; $defBonus['def'] *= 1.25; }

        if ($atkFormation === 1) { $atkBonus['atk'] *= 1.25; $atkBonus['def'] *= 0.75; }
        elseif ($atkFormation === 2) { $atkBonus['atk'] *= 0.75; $atkBonus['def'] *= 1.25; }

        // Calculate combat stats for land units (types 0-8, 11-12; skip 9-10 naval)
        $defHP = 0; $defATK = 0; $defDEF = 0;
        $atkHP = 0; $atkATK = 0; $atkDEF = 0;

        for ($i = 0; $i < count($defUnits); $i++) {
            if ($i >= 9 && $i <= 10) continue; // Skip naval
            $hp = (int) ($defUnits[$i]['hp'] ?? 0) + ($defUUp[$i] ?? 0);
            $atk = (int) ($defUnits[$i]['attack'] ?? 0) + ($defWUp[$i] ?? 0);
            $def = (int) ($defUnits[$i]['defense'] ?? 0) + ($defAUp[$i] ?? 0);
            $defHP += $hp * ($defArmy[$i] ?? 0);
            $defATK += $atk * ($defArmy[$i] ?? 0);
            $defDEF += $def;
        }

        for ($i = 0; $i < count($atkUnits); $i++) {
            if ($i >= 9 && $i <= 10) continue;
            $hp = (int) ($atkUnits[$i]['hp'] ?? 0) + ($atkUUp[$i] ?? 0);
            $atk = (int) ($atkUnits[$i]['attack'] ?? 0) + ($atkWUp[$i] ?? 0);
            $def = (int) ($atkUnits[$i]['defense'] ?? 0) + ($atkAUp[$i] ?? 0);
            $atkHP += $hp * ($atkArmy[$i] ?? 0);
            $atkATK += $atk * ($atkArmy[$i] ?? 0);
            $atkDEF += $def;
        }

        // Add general bonuses (format: presence-level-unit_type-formation-xp)
        if ((int) ($defGen[0] ?? 0) > 0 && isset($defUnits[(int) ($defGen[2] ?? 0)])) {
            $gUnit = $defUnits[(int) $defGen[2]];
            $gLevel = (int) ($defGen[1] ?? 1);
            $defATK += ((int) ($gUnit['attack'] ?? 0) + ($defWUp[(int) $defGen[2]] ?? 0)) * $gLevel + $gLevel;
            $defDEF += (int) ($gUnit['defense'] ?? 0) + ($defAUp[(int) $defGen[2]] ?? 0) + $gLevel;
        }
        if ((int) ($atkGen[0] ?? 0) > 0 && isset($atkUnits[(int) ($atkGen[2] ?? 0)])) {
            $gUnit = $atkUnits[(int) $atkGen[2]];
            $gLevel = (int) ($atkGen[1] ?? 1);
            $atkATK += ((int) ($gUnit['attack'] ?? 0) + ($atkWUp[(int) $atkGen[2]] ?? 0)) * $gLevel + $gLevel;
            $atkDEF += (int) ($gUnit['defense'] ?? 0) + ($atkAUp[(int) $atkGen[2]] ?? 0) + $gLevel;
        }

        // Average DEF, apply bonuses
        $unitCount = max(1, count($defUnits) - 2); // -2 for naval
        $defDEF = ($defDEF / $unitCount);
        $atkDEF = ($atkDEF / $unitCount);

        $defATK += $defATK * $defBonus['atk'] / 100;
        $defDEF += $defDEF * $defBonus['def'] / 100;
        $atkATK += $atkATK * $atkBonus['atk'] / 100;
        $atkDEF += $atkDEF * $atkBonus['def'] / 100;

        // Combat resolution
        $attackerWins = false;
        $atkDmg = 0.0;
        $defDmg = 0.0;

        if ($atkHP > 0 && $defHP > 0 && $atkATK > 0 && $defATK > 0) {
            $ah = $defHP / max(1, $atkATK) * max(0.01, (100 - $defDEF) / 100);
            $dh = $atkHP / max(1, $defATK) * max(0.01, (100 - $atkDEF) / 100);

            $atkDmg = max(0, min(1, ($atkHP - $ah * $defATK * max(0.01, (100 - $atkDEF) / 100)) / max(1, $atkHP)));
            $defDmg = max(0, min(1, ($defHP - $dh * $atkATK * max(0.01, (100 - $defDEF) / 100)) / max(1, $defHP)));

            $attackerWins = ($ah < $dh);
        } elseif ($atkHP > 0 && $defHP === 0) {
            // No defenders
            $attackerWins = true;
            $atkDmg = 1.0;
            $defDmg = 0.0;
        } else {
            // No attackers or both zero
            $attackerWins = false;
            $atkDmg = 0.0;
            $defDmg = 1.0;
        }

        if ($attackerWins) {
            if ($type === 2) {
                // Attack: defenders wiped, attackers take casualties
                $atkSurvivors = 0;
                for ($i = 0; $i < count($atkArmy); $i++) {
                    $atkArmy[$i] = (int) ceil(($atkArmy[$i] ?? 0) * $atkDmg);
                    $atkSurvivors += $atkArmy[$i];
                }
                $defArmy = array_fill(0, count($defArmy), 0);

                // Siege damage (ram/catapult = types 7,8)
                if (($atkArmy[7] ?? 0) > 0 || ($atkArmy[8] ?? 0) > 0) {
                    $b = rand(0, count($defBuildings) - 1);
                    if ($defBuildings[$b] > 0) $defBuildings[$b]--;
                }

                // Loot
                $capacity = $atkSurvivors * 10;
                $capacityLeft = $capacity;
                for ($i = 0; $i < 5; $i++) {
                    if ($capacityLeft <= 0) {
                        break;
                    }
                    $available = max(0, $defResources[$i] - ($defLimits[5] ?? 0));
                    $take = min($available, $capacityLeft);
                    $rLoot[$i] = $take;
                    $defResources[$i] -= $take;
                    $capacityLeft -= $take;
                }
                $wCapacityLeft = $atkSurvivors;
                for ($i = 0; $i < count($defWeapons); $i++) {
                    if ($wCapacityLeft <= 0) {
                        break;
                    }
                    $take = min($defWeapons[$i], $wCapacityLeft);
                    $wLoot[$i] = $take;
                    $defWeapons[$i] -= $take;
                    $wCapacityLeft -= $take;
                }
            } else {
                // Raid: both sides take proportional casualties
                $atkSurvivors = 0;
                for ($i = 0; $i < count($atkArmy); $i++) {
                    $qAdmg = max(0, min(1, ($atkHP - $defATK * max(0.01, (100 - $atkDEF) / 100)) / max(1, $atkHP)));
                    $qDdmg = max(0, min(1, ($defHP - $atkATK * max(0.01, (100 - $defDEF) / 100)) / max(1, $defHP)));
                    $atkArmy[$i] = (int) ceil(($atkArmy[$i] ?? 0) * $qAdmg);
                    $defArmy[$i] = (int) ceil(($defArmy[$i] ?? 0) * $qDdmg);
                    $atkSurvivors += $atkArmy[$i];
                }

                $capacity = $atkSurvivors * 10;
                $capacityLeft = $capacity;
                for ($i = 0; $i < 5; $i++) {
                    if ($capacityLeft <= 0) {
                        break;
                    }
                    $available = max(0, $defResources[$i] - ($defLimits[5] ?? 0));
                    $take = min($available, $capacityLeft);
                    $rLoot[$i] = $take;
                    $defResources[$i] -= $take;
                    $capacityLeft -= $take;
                }
                $wCapacityLeft = $atkSurvivors;
                for ($i = 0; $i < count($defWeapons); $i++) {
                    if ($wCapacityLeft <= 0) {
                        break;
                    }
                    $take = min($defWeapons[$i], $wCapacityLeft);
                    $wLoot[$i] = $take;
                    $defWeapons[$i] -= $take;
                    $wCapacityLeft -= $take;
                }

                // Spy intel: no resource/weapon looting for spy missions
                if ($type === 3) {
                    $rLoot = [0.0, 0.0, 0.0, 0.0, 0.0];
                    $wLoot = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];
                }
            }
        } else {
            // Defenders win
            if ($type === 2) {
                // Attack: attackers wiped
                for ($i = 0; $i < count($defArmy); $i++) {
                    $defArmy[$i] = (int) ceil(($defArmy[$i] ?? 0) * $defDmg);
                }
                $atkArmy = array_fill(0, count($atkArmy), 0);
            } else {
                // Raid: both take casualties
                for ($i = 0; $i < count($atkArmy); $i++) {
                    $qAdmg = max(0, min(1, ($atkHP - $defATK * max(0.01, (100 - $atkDEF) / 100)) / max(1, $atkHP)));
                    $qDdmg = max(0, min(1, ($defHP - $atkATK * max(0.01, (100 - $defDEF) / 100)) / max(1, $defHP)));
                    $defArmy[$i] = (int) ceil(($defArmy[$i] ?? 0) * $qDdmg);
                    $atkArmy[$i] = (int) ceil(($atkArmy[$i] ?? 0) * $qAdmg);
                }
            }
            $rLoot = [0.0, 0.0, 0.0, 0.0, 0.0];
            $wLoot = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];
        }

        return [
            'defArmy' => $defArmy,
            'atkArmy' => $atkArmy,
            'defResources' => $defResources,
            'defWeapons' => $defWeapons,
            'defBuildings' => $defBuildings,
            'rLoot' => $rLoot,
            'wLoot' => $wLoot,
            'attackerWins' => $attackerWins,
        ];
    }

    /**
     * Send battle reports to both attacker and defender owners.
     */
    private function sendBattleReport(array $from, array $target, array $atkArmyBefore, array $defArmyBefore, array $result, int $type, string $intel = ''): void
    {
        $typeNames = [0 => 'Reinforcement', 1 => 'Raid', 2 => 'Attack', 3 => 'Spy'];
        $typeName = $typeNames[$type] ?? 'Battle';
        $outcome = $result['attackerWins'] ? 'Attacker won' : 'Defender won';

        $content = $typeName . " on " . $target['name'] . " from " . $from['name'] . "\n";
        $content .= "Result: " . $outcome . "\n";
        $content .= "Attacker army before: " . implode('-', $atkArmyBefore) . "\n";
        $content .= "Attacker army after: " . implode('-', $result['atkArmy']) . "\n";
        $content .= "Defender army before: " . implode('-', $defArmyBefore) . "\n";
        $content .= "Defender army after: " . implode('-', $result['defArmy']) . "\n";

        if ($result['attackerWins'] && $type !== 3) {
            $content .= "Resources looted: " . implode('-', array_map(fn($v) => (string) round($v), $result['rLoot'])) . "\n";
            $content .= "Weapons looted: " . implode('-', $result['wLoot']) . "\n";
        }

        // Add intel data for spy missions
        if ($type === 3 && !empty($intel)) {
            $intelData = json_decode($intel, true);
            if ($intelData) {
                $content .= "\n--- Intelligence Report ---\n";
                if (isset($intelData['army'])) {
                    $content .= "Defender army: " . $intelData['army'] . "\n";
                }
                if (isset($intelData['resources'])) {
                    $content .= "Defender resources: " . $intelData['resources'] . "\n";
                }
                if (isset($intelData['buildings'])) {
                    $content .= "Defender buildings: " . $intelData['buildings'] . "\n";
                }
            }
        }

        $subject = $typeName . " report: " . $target['name'];
        $preferenceService = new PreferenceService($this->db);

        // Report to attacker (from's owner)
        $attackerOwner = (int) ($from['owner'] ?? 0);
        if ($attackerOwner > 0 && $preferenceService->isEnabled($attackerOwner, 'combatReports')) {
            $this->db->exec(
                "INSERT INTO reports (recipient, subject, contents, sent) VALUES (?, ?, ?, NOW())",
                [$attackerOwner, $subject, $content]
            );
        }

        // Report to defender (target's owner)
        $defenderOwner = (int) ($target['owner'] ?? 0);
        if ($defenderOwner > 0 && $preferenceService->isEnabled($defenderOwner, 'combatReports')) {
            $this->db->exec(
                "INSERT INTO reports (recipient, subject, contents, sent) VALUES (?, ?, ?, NOW())",
                [$defenderOwner, $subject, $content]
            );
        }
    }
}

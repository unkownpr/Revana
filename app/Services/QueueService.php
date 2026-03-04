<?php

declare(strict_types=1);

namespace Devana\Services;

use Devana\Helpers\DataParser;
use Devana\Services\BarbarianService;
use Devana\Services\AchievementService;
use Devana\Services\NotificationService;
use Devana\Services\SeasonService;
use Devana\Services\WeeklyMissionService;

final class QueueService
{
    private \DB\SQL $db;

    public function __construct(\DB\SQL $db)
    {
        $this->db = $db;
    }

    /**
     * Process construction queue. When dueTime has passed, apply the building effect.
     */
    public function processConstructionQueue(int $townId, int $factionId): void
    {
        $town = $this->db->exec('SELECT * FROM towns WHERE id = ?', [$townId]);

        if (empty($town)) {
            return;
        }

        $town = $town[0];
        $buildings = $this->db->exec(
            'SELECT * FROM buildings WHERE faction = ? ORDER BY type',
            [$factionId]
        );
        $data = DataParser::toIntArray($town['buildings']);
        $land = array_map(
            fn(string $s): array => DataParser::toIntArray($s),
            explode('/', $town['land'])
        );
        $prod = DataParser::toIntArray($town['production']);
        $lim = DataParser::toIntArray($town['limits']);

        $this->db->begin();
        $processedCount = 0;
        try {
            // Lock queue rows inside the transaction to prevent double-processing
            // if two requests arrive simultaneously for the same town.
            $queue = $this->db->exec(
                'SELECT TIMESTAMPDIFF(SECOND, NOW(), dueTime) AS remaining, b, subB FROM c_queue WHERE town = ? ORDER BY dueTime ASC FOR UPDATE',
                [$townId]
            );

            foreach ($queue as $item) {
                if ((int) $item['remaining'] > 0) {
                    continue;
                }

                $b = (int) $item['b'];
                $subB = (int) $item['subB'];

                if ($subB > -1) {
                    $this->completeLandBuilding($townId, $b, $subB, $land, $prod, $buildings);
                } else {
                    $this->completeMainBuilding($townId, $b, $data, $lim, $prod, $buildings, $town);
                }

                $this->db->exec(
                    'DELETE FROM c_queue WHERE town = ? AND b = ? AND subB = ?',
                    [$townId, $b, $subB]
                );
                $processedCount++;
            }

            if ($processedCount > 0) {
                (new TownService($this->db))->recalculatePopulation($townId, $factionId);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        // Mission trigger: BUILD
        if ($processedCount > 0) {
            $ownerId = (int) ($town['owner'] ?? 0);
            if ($ownerId > 0) {
                try {
                    (new MissionService($this->db))->incrementProgress($ownerId, MissionService::TYPE_BUILD, $processedCount);
                } catch (\Throwable $e) {
                    // non-critical
                }
                try {
                    (new WeeklyMissionService($this->db))->incrementProgress($ownerId, WeeklyMissionService::TYPE_BUILD, $processedCount);
                } catch (\Throwable $e) {
                    // non-critical
                }
                try {
                    // Update total_builds stat
                    $this->db->exec('UPDATE users SET total_builds = total_builds + ? WHERE id = ?', [$processedCount, $ownerId]);
                    $totalRow = $this->db->exec('SELECT total_builds FROM users WHERE id = ? LIMIT 1', [$ownerId]);
                    $total = (int) ($totalRow[0]['total_builds'] ?? 0);
                    $achService = new AchievementService($this->db);
                    $achService->check($ownerId, 'first_build', $total);
                    $achService->check($ownerId, 'builder_10', $total);
                    $achService->check($ownerId, 'builder_50', $total);
                } catch (\Throwable $e) {
                    // non-critical
                }
                try {
                    // Notification: build done
                    (new NotificationService($this->db))->notify($ownerId, NotificationService::TYPE_BUILD_DONE, 'notifBuildDone', '/town/' . $townId);
                } catch (\Throwable $e) {
                    // non-critical
                }
                try {
                    (new SeasonService($this->db))->addScore($ownerId, $processedCount * SeasonService::SCORE_BUILD, 'build');
                } catch (\Throwable $e) {
                    // non-critical
                }
            }
        }
    }

    /**
     * @param array<int, array<int, int>> $land
     * @param array<int, int> $prod
     * @param array<int, array<string, mixed>> $buildings
     */
    private function completeLandBuilding(
        int $townId,
        int $b,
        int $subB,
        array &$land,
        array &$prod,
        array $buildings
    ): void {
        $land[$b][$subB]++;
        $landStr = implode('/', array_map(
            fn(array $a): string => DataParser::serialize($a),
            $land
        ));

        $output = DataParser::toIntArray($buildings[$b]['output'] ?? '0');
        $totalProd = 0;

        foreach ($land[$b] as $level) {
            if ($level > 0 && isset($output[$level - 1])) {
                $totalProd += $output[$level - 1];
            }
        }

        $prod[$b] = $totalProd;

        $this->db->exec(
            'UPDATE towns SET land = ?, production = ? WHERE id = ?',
            [$landStr, DataParser::serialize($prod), $townId]
        );
    }

    /**
     * @param array<int, int> $data
     * @param array<int, int> $lim
     * @param array<int, int> $prod
     * @param array<int, array<string, mixed>> $buildings
     * @param array<string, mixed> $town
     */
    private function completeMainBuilding(
        int $townId,
        int $b,
        array &$data,
        array &$lim,
        array &$prod,
        array $buildings,
        array $town
    ): void {
        if ($b <= 3) {
            $data[$b] = 1;
        } else {
            $data[$b]++;
        }

        $output = DataParser::toIntArray($buildings[$b]['output'] ?? '0');
        $newLevel = $data[$b];

        $limitMap = [
            4 => 0, 5 => 1, 6 => 5, 7 => 4, 8 => 3,
            13 => 6, 14 => 7, 15 => 8,
            18 => 9, 19 => 10, 20 => 11, 21 => 12,
        ];

        if (isset($limitMap[$b]) && isset($output[$newLevel - 1])) {
            $lim[$limitMap[$b]] = $output[$newLevel - 1];
        }

        if ($b === 7) {
            $lim[2] = ($lim[2] ?? 0) + 800;
        }

        if ($b === 11 && isset($output[$newLevel - 1])) {
            $moraleBonus = $output[$newLevel - 1];
            $morale = 100 - $prod[4] + $moraleBonus;
            $this->db->exec(
                'UPDATE towns SET morale = ? WHERE id = ?',
                [$morale, $townId]
            );
        }

        $this->db->exec(
            'UPDATE towns SET buildings = ?, `limits` = ? WHERE id = ?',
            [DataParser::serialize($data), DataParser::serialize($lim), $townId]
        );

        // Auto-update player points
        $owner = $this->db->exec('SELECT owner FROM towns WHERE id = ?', [$townId]);
        if (!empty($owner) && (int) $owner[0]['owner'] > 0) {
            (new UserService($this->db))->recalculatePoints((int) $owner[0]['owner']);
        }
    }

    /**
     * Process weapon queue.
     */
    public function processWeaponQueue(int $townId): void
    {
        $town = $this->db->exec('SELECT weapons FROM towns WHERE id = ?', [$townId]);

        if (empty($town)) {
            return;
        }

        $weapons = DataParser::toIntArray($town[0]['weapons']);

        $this->db->begin();
        try {
            $queue = $this->db->exec(
                'SELECT TIMESTAMPDIFF(SECOND, NOW(), dueTime) AS remaining, type, quantity FROM w_queue WHERE town = ? ORDER BY dueTime ASC FOR UPDATE',
                [$townId]
            );

            foreach ($queue as $item) {
                if ((int) $item['remaining'] > 0) {
                    continue;
                }

                $weapons[(int) $item['type']] += (int) $item['quantity'];
                $this->db->exec(
                    'DELETE FROM w_queue WHERE town = ? AND type = ?',
                    [$townId, $item['type']]
                );
            }

            $this->db->exec(
                'UPDATE towns SET weapons = ? WHERE id = ?',
                [DataParser::serialize($weapons), $townId]
            );
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * Process unit queue.
     */
    public function processUnitQueue(int $townId): void
    {
        $town = $this->db->exec('SELECT army FROM towns WHERE id = ?', [$townId]);

        if (empty($town)) {
            return;
        }

        $army = DataParser::toIntArray($town[0]['army']);

        $this->db->begin();
        $totalTrained = 0;
        try {
            $queue = $this->db->exec(
                'SELECT TIMESTAMPDIFF(SECOND, NOW(), dueTime) AS remaining, type, quantity FROM u_queue WHERE town = ? ORDER BY dueTime ASC FOR UPDATE',
                [$townId]
            );

            foreach ($queue as $item) {
                if ((int) $item['remaining'] > 0) {
                    continue;
                }

                $qty = (int) $item['quantity'];
                $army[(int) $item['type']] += $qty;
                $totalTrained += $qty;
                $this->db->exec(
                    'DELETE FROM u_queue WHERE town = ? AND type = ?',
                    [$townId, $item['type']]
                );
            }

            $upkeep = array_sum($army);
            $this->db->exec(
                'UPDATE towns SET army = ?, upkeep = ? WHERE id = ?',
                [DataParser::serialize($army), $upkeep, $townId]
            );
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        // Mission trigger: TRAIN
        if ($totalTrained > 0) {
            $ownerRow = $this->db->exec('SELECT owner FROM towns WHERE id = ? LIMIT 1', [$townId]);
            $ownerId  = (int) ($ownerRow[0]['owner'] ?? 0);
            if ($ownerId > 0) {
                try {
                    (new MissionService($this->db))->incrementProgress($ownerId, MissionService::TYPE_TRAIN, $totalTrained);
                } catch (\Throwable $e) {
                    // non-critical
                }
                try {
                    (new WeeklyMissionService($this->db))->incrementProgress($ownerId, WeeklyMissionService::TYPE_TRAIN, $totalTrained);
                } catch (\Throwable $e) {
                    // non-critical
                }
                try {
                    $this->db->exec('UPDATE users SET total_trains = total_trains + ? WHERE id = ?', [$totalTrained, $ownerId]);
                    $totalRow = $this->db->exec('SELECT total_trains FROM users WHERE id = ? LIMIT 1', [$ownerId]);
                    $total = (int) ($totalRow[0]['total_trains'] ?? 0);
                    $achService = new AchievementService($this->db);
                    $achService->check($ownerId, 'first_train', $total);
                    $achService->check($ownerId, 'train_100', $total);
                } catch (\Throwable $e) {
                    // non-critical
                }
                try {
                    $units10 = (int) floor($totalTrained / 10);
                    if ($units10 > 0) {
                        (new SeasonService($this->db))->addScore($ownerId, $units10 * SeasonService::SCORE_TRAIN, 'train');
                    }
                } catch (\Throwable $e) {
                    // non-critical
                }
            }
        }
    }

    /**
     * Process unit upgrade queue.
     */
    public function processUpgradeQueue(int $townId): void
    {
        $town = $this->db->exec(
            'SELECT uUpgrades, wUpgrades, aUpgrades FROM towns WHERE id = ?',
            [$townId]
        );

        if (empty($town)) {
            return;
        }

        $colMap = self::UPGRADE_TREE_MAP;
        $upgradeData = [];
        foreach ($colMap as $tree => $col) {
            $upgradeData[$tree] = DataParser::toIntArray($town[0][$col]);
        }

        $this->db->begin();
        try {
            $queue = $this->db->exec(
                'SELECT TIMESTAMPDIFF(SECOND, NOW(), dueTime) AS remaining, unit, tree FROM uup_queue WHERE town = ? ORDER BY dueTime ASC FOR UPDATE',
                [$townId]
            );

            foreach ($queue as $item) {
                if ((int) $item['remaining'] > 0) {
                    continue;
                }

                $tree = (int) $item['tree'];
                $unit = (int) $item['unit'];

                if (!isset($upgradeData[$tree][$unit])) {
                    continue;
                }

                $upgradeData[$tree][$unit]++;
                $col = $colMap[$tree];
                $this->db->exec(
                    "UPDATE towns SET {$col} = ? WHERE id = ?",
                    [DataParser::serialize($upgradeData[$tree]), $townId]
                );

                $this->db->exec(
                    'DELETE FROM uup_queue WHERE town = ? AND unit = ? AND tree = ?',
                    [$townId, $unit, $tree]
                );
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * Process trade queue - complete trades where dueTime has passed.
     */
    public function processTradeQueue(int $townId): void
    {
        // Quick check before locking — avoid starting a transaction if nothing to process.
        $hasItems = $this->db->exec(
            'SELECT 1 FROM t_queue WHERE type IN (1, 2) AND (seller = ? OR buyer = ?) AND dueTime <= NOW() LIMIT 1',
            [$townId, $townId]
        );

        if (empty($hasItems)) {
            return;
        }

        $this->db->begin();
        try {
            $queue = $this->db->exec(
                'SELECT TIMESTAMPDIFF(SECOND, NOW(), dueTime) AS remaining, seller, buyer, sType, sSubType, sQ, bType, bSubType, bQ, type FROM t_queue WHERE type IN (1, 2) AND (seller = ? OR buyer = ?) ORDER BY dueTime ASC FOR UPDATE',
                [$townId, $townId]
            );

            foreach ($queue as $offer) {
                if ((int) $offer['remaining'] > 0) {
                    continue;
                }

                $sellerId = (int) $offer['seller'];
                $buyerId = (int) $offer['buyer'];
                $queueType = (int) $offer['type'];

                if ($queueType === 2) {
                    $this->addTradeGoods($buyerId, (int) $offer['sType'], (int) $offer['sSubType'], (int) $offer['sQ']);
                    $this->db->exec(
                        'DELETE FROM t_queue WHERE seller = ? AND buyer = ? AND sType = ? AND sSubType = ? AND bType = ? AND bSubType = ? AND type = 2',
                        [$sellerId, $buyerId, $offer['sType'], $offer['sSubType'], $offer['bType'], $offer['bSubType']]
                    );
                    continue;
                }

                $this->addTradeGoods($sellerId, (int) $offer['bType'], (int) $offer['bSubType'], (int) $offer['bQ']);
                $this->addTradeGoods($buyerId, (int) $offer['sType'], (int) $offer['sSubType'], (int) $offer['sQ']);

                $this->db->exec(
                    'DELETE FROM t_queue WHERE seller = ? AND sType = ? AND sSubType = ? AND bType = ? AND bSubType = ? AND type = 1',
                    [$sellerId, $offer['sType'], $offer['sSubType'], $offer['bType'], $offer['bSubType']]
                );
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * Add goods to a town from a completed trade.
     */
    private function addTradeGoods(int $townId, int $type, int $subType, int $quantity): void
    {
        if ($type === 0) {
            $town = $this->db->exec('SELECT resources, `limits` FROM towns WHERE id = ?', [$townId]);
            if (empty($town)) return;
            $res = DataParser::toFloatArray($town[0]['resources']);
            $lim = DataParser::toFloatArray($town[0]['limits']);
            // limits: [0]=crop, [1]=lumber/stone/iron, [2]=gold
            $limitIdx = [0 => 0, 1 => 1, 2 => 1, 3 => 1, 4 => 2];
            $cap = $lim[$limitIdx[$subType] ?? 1] ?? PHP_FLOAT_MAX;
            $res[$subType] = min(($res[$subType] ?? 0) + $quantity, $cap);
            $this->db->exec(
                'UPDATE towns SET resources = ? WHERE id = ?',
                [DataParser::serializeRounded($res), $townId]
            );
        } else {
            $town = $this->db->exec('SELECT weapons FROM towns WHERE id = ?', [$townId]);
            if (empty($town)) return;
            $weapons = DataParser::toIntArray($town[0]['weapons']);
            $weapons[$subType] = ($weapons[$subType] ?? 0) + $quantity;
            $this->db->exec(
                'UPDATE towns SET weapons = ? WHERE id = ?',
                [DataParser::serialize($weapons), $townId]
            );
        }
    }

    /**
     * Process army queue - handle arrivals and returns.
     */
    public function processArmyQueue(int $townId): void
    {
        $hasItems = $this->db->exec(
            'SELECT 1 FROM a_queue WHERE (town = ? OR target = ?) AND dueTime <= NOW() LIMIT 1',
            [$townId, $townId]
        );

        if (empty($hasItems)) {
            return;
        }

        $this->db->begin();
        try {
            $queue = $this->db->exec(
                'SELECT TIMESTAMPDIFF(SECOND, NOW(), dueTime) AS remaining, town, target, id, type, phase, army, general, rLoot, wLoot, sent FROM a_queue WHERE (town = ? OR target = ?) ORDER BY dueTime ASC FOR UPDATE',
                [$townId, $townId]
            );

            foreach ($queue as $movement) {
                if ((int) $movement['remaining'] > 0) {
                    continue;
                }

                $phase = (int) $movement['phase'];
                $type = (int) $movement['type'];
                $fromTown = (int) $movement['town'];
                $targetTown = (int) $movement['target'];

                if ($phase === 0) {
                    if ($targetTown > BarbarianService::CAMP_OFFSET) {
                        // Arrival at a barbarian camp.
                        $campId = $targetTown - BarbarianService::CAMP_OFFSET;
                        $barbarianService = new BarbarianService($this->db);
                        $result = $barbarianService->processArrival($movement, $campId);
                    } else {
                        $battleService = new BattleService($this->db);
                        $result = $battleService->processArrival($movement);
                    }

                    if ($type === 0) {
                        $this->mergeArmyIntoTown($targetTown, $movement['army']);
                        $this->deliverResources($targetTown, $movement['rLoot']);

                        $this->db->exec(
                            'DELETE FROM a_queue WHERE town = ? AND id = ?',
                            [$fromTown, $movement['id']]
                        );
                    } else {
                        $sentParts = DataParser::toIntArray($movement['sent']);
                        $returnSeconds = ($sentParts[0] ?? 0) * 3600 + ($sentParts[1] ?? 0) * 60;

                        $this->db->exec(
                            'UPDATE a_queue SET phase = 1, dueTime = DATE_ADD(NOW(), INTERVAL ? SECOND), army = ?, rLoot = ?, wLoot = ?, intel = ? WHERE town = ? AND id = ?',
                            [
                                $returnSeconds,
                                $result['army'] ?? $movement['army'],
                                $result['rLoot'] ?? '0-0-0-0-0',
                                $result['wLoot'] ?? '0-0-0-0-0-0-0-0-0-0-0',
                                $result['intel'] ?? '',
                                $fromTown, $movement['id']
                            ]
                        );
                    }
                } else {
                    $this->mergeArmyIntoTown($fromTown, $movement['army']);
                    $this->deliverResources($fromTown, $movement['rLoot']);
                    $this->deliverWeapons($fromTown, $movement['wLoot']);

                    $this->db->exec(
                        'DELETE FROM a_queue WHERE town = ? AND id = ?',
                        [$fromTown, $movement['id']]
                    );

                    // Notify army return
                    try {
                        $fromTownRow = $this->db->exec('SELECT owner FROM towns WHERE id = ? LIMIT 1', [$fromTown]);
                        $fromOwnerId = (int) ($fromTownRow[0]['owner'] ?? 0);
                        if ($fromOwnerId > 0) {
                            (new NotificationService($this->db))->notify($fromOwnerId, NotificationService::TYPE_ARMY_RETURN, 'notifArmyReturn', '/town/' . $fromTown);
                        }
                    } catch (\Throwable $e) {
                        // non-critical
                    }
                }
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * Deliver resources to a town (from reinforcement or loot return).
     */
    private function deliverResources(int $townId, string $lootStr): void
    {
        $loot = DataParser::toIntArray($lootStr);
        if (array_sum($loot) <= 0) {
            return;
        }

        $town = $this->db->exec('SELECT resources, `limits` FROM towns WHERE id = ?', [$townId]);
        if (empty($town)) return;

        $res = DataParser::toFloatArray($town[0]['resources']);
        $lim = DataParser::toFloatArray($town[0]['limits']);
        // limits: [0]=crop, [1]=lumber/stone/iron, [2]=gold
        $limitIdx = [0 => 0, 1 => 1, 2 => 1, 3 => 1, 4 => 2];
        for ($i = 0; $i < min(count($res), count($loot)); $i++) {
            $cap = $lim[$limitIdx[$i] ?? 1] ?? PHP_FLOAT_MAX;
            $res[$i] = min($res[$i] + $loot[$i], $cap);
        }

        $this->db->exec(
            'UPDATE towns SET resources = ? WHERE id = ?',
            [DataParser::serializeRounded($res), $townId]
        );
    }

    /**
     * Deliver weapons to a town (from loot return).
     */
    private function deliverWeapons(int $townId, string $lootStr): void
    {
        $loot = DataParser::toIntArray($lootStr);
        if (array_sum($loot) <= 0) {
            return;
        }

        $town = $this->db->exec('SELECT weapons FROM towns WHERE id = ?', [$townId]);
        if (empty($town)) return;

        $weapons = DataParser::toIntArray($town[0]['weapons']);
        for ($i = 0; $i < min(count($weapons), count($loot)); $i++) {
            $weapons[$i] += $loot[$i];
        }

        $this->db->exec(
            'UPDATE towns SET weapons = ? WHERE id = ?',
            [DataParser::serialize($weapons), $townId]
        );
    }

    /**
     * Merge army into a town's garrison.
     */
    private function mergeArmyIntoTown(int $townId, string $armyStr): void
    {
        $town = $this->db->exec('SELECT army FROM towns WHERE id = ?', [$townId]);
        if (empty($town)) return;

        $townArmy = DataParser::toIntArray($town[0]['army']);
        $addArmy = DataParser::toIntArray($armyStr);

        for ($i = 0; $i < count($townArmy); $i++) {
            $townArmy[$i] += ($addArmy[$i] ?? 0);
        }

        $this->db->exec(
            'UPDATE towns SET army = ?, upkeep = ? WHERE id = ?',
            [DataParser::serialize($townArmy), array_sum($townArmy), $townId]
        );
    }

    // ── Display queries ──────────────────────────────────

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getConstructionQueue(int $townId): array
    {
        $rows = $this->db->exec(
            'SELECT TIMEDIFF(dueTime, NOW()) AS timeLeft, b, subB FROM c_queue WHERE town = ? ORDER BY dueTime ASC',
            [$townId]
        );

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'b' => (int) $row['b'],
                'subB' => (int) $row['subB'],
                'timeLeft' => $row['timeLeft'],
            ];
        }

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getArmyMovements(int $townId): array
    {
        $rows = $this->db->exec(
            'SELECT TIMEDIFF(dueTime, NOW()) AS timeLeft, town, target, type, phase FROM a_queue WHERE town = ? OR target = ? ORDER BY dueTime ASC',
            [$townId, $townId]
        );

        $result = [];
        $typeNames = [0 => 'Reinforce', 1 => 'Raid', 2 => 'Attack', 3 => 'Spy'];
        foreach ($rows as $row) {
            $from = $this->db->exec('SELECT name FROM towns WHERE id = ? LIMIT 1', [(int) $row['town']]);
            $to = $this->db->exec('SELECT name FROM towns WHERE id = ? LIMIT 1', [(int) $row['target']]);
            $typeName = $typeNames[(int) $row['type']] ?? 'Movement';
            $fromName = $from[0]['name'] ?? 'Unknown';
            $toName = $to[0]['name'] ?? 'Unknown';
            $phase = (int) $row['phase'];

            if ($phase === 0) {
                $label = $fromName . ' → ' . $typeName;
            } else {
                $label = 'Return from ' . $typeName . ' on ' . $toName;
            }

            $result[] = [
                'label' => $label,
                'timeLeft' => $row['timeLeft'],
            ];
        }

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getWeaponQueue(int $townId): array
    {
        return $this->db->exec(
            'SELECT TIMEDIFF(dueTime, NOW()) AS timeLeft, type, quantity FROM w_queue WHERE town = ? ORDER BY dueTime ASC',
            [$townId]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getUnitQueue(int $townId): array
    {
        return $this->db->exec(
            'SELECT TIMEDIFF(dueTime, NOW()) AS timeLeft, type, quantity FROM u_queue WHERE town = ? ORDER BY dueTime ASC',
            [$townId]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getUpgradeQueue(int $townId): array
    {
        return $this->db->exec(
            'SELECT TIMEDIFF(dueTime, NOW()) AS timeLeft, unit, tree FROM uup_queue WHERE town = ? ORDER BY dueTime ASC',
            [$townId]
        );
    }

    // ── Queue management ─────────────────────────────────

    public function isConstructionQueued(int $townId, int $buildingType, int $subB): bool
    {
        $result = $this->db->exec(
            'SELECT 1 FROM c_queue WHERE town = ? AND b = ? AND subB = ?',
            [$townId, $buildingType, $subB]
        );
        return !empty($result);
    }

    public function addConstruction(int $townId, int $buildingType, int $subB, string $duration, int $maxQueueItems = PHP_INT_MAX): bool
    {
        if ($this->isConstructionQueued($townId, $buildingType, $subB)) {
            return false;
        }

        if ($maxQueueItems < PHP_INT_MAX) {
            $countResult = $this->db->exec(
                'SELECT COUNT(*) AS c FROM c_queue WHERE town = ?',
                [$townId]
            );
            $queued = (int) ($countResult[0]['c'] ?? 0);
            if ($queued >= $maxQueueItems) {
                return false;
            }
        }

        $this->db->exec(
            'INSERT INTO c_queue (town, dueTime, b, subB) VALUES (?, DATE_ADD(NOW(), INTERVAL ? SECOND), ?, ?)',
            [$townId, DataParser::durationToSeconds($duration), $buildingType, $subB]
        );
        return true;
    }

    /**
     * @return array<int, bool>
     */
    public function getUpgradeStatus(int $townId): array
    {
        $rows = $this->db->exec(
            'SELECT unit FROM uup_queue WHERE town = ?',
            [$townId]
        );
        $status = [];
        foreach ($rows as $row) {
            $status[(int) $row['unit']] = true;
        }
        return $status;
    }

    public function addUpgrade(int $townId, int $unitType, int $tree, string $duration): void
    {
        $totalSeconds = DataParser::durationToSeconds($duration);

        $maxDue = $this->db->exec(
            'SELECT MAX(dueTime) AS maxDue FROM uup_queue WHERE town = ?',
            [$townId]
        );

        if (!empty($maxDue) && $maxDue[0]['maxDue'] !== null) {
            $this->db->exec(
                'INSERT INTO uup_queue (town, unit, tree, dueTime) VALUES (?, ?, ?, DATE_ADD(?, INTERVAL ? SECOND))',
                [$townId, $unitType, $tree, $maxDue[0]['maxDue'], $totalSeconds]
            );
        } else {
            $this->db->exec(
                'INSERT INTO uup_queue (town, unit, tree, dueTime) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))',
                [$townId, $unitType, $tree, $totalSeconds]
            );
        }
    }

    // ── Constants ────────────────────────────────────────

    /** Mapping of upgrade tree IDs to database column names. */
    public const UPGRADE_TREE_MAP = [
        17 => 'uUpgrades',
        18 => 'wUpgrades',
        19 => 'aUpgrades',
    ];
}

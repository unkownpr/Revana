<?php declare(strict_types=1);

namespace Devana\Services;

use Devana\Helpers\DataParser;

final class BarbarianService
{
    private \DB\SQL $db;

    /**
     * Offset added to a real camp ID when stored in a_queue.target.
     * Values above this threshold in a_queue.target mean a camp attack.
     */
    public const CAMP_OFFSET = 100000;

    /** Pre-built army string per camp level (13 unit slots, faction-1 indices). */
    private const LEVEL_ARMIES = [
        1 => '0-3-2-0-0-0-0-0-0-0-0-0-0',
        2 => '0-6-4-0-0-0-0-0-0-0-0-0-0',
        3 => '0-10-6-2-0-0-0-0-0-0-0-0-0',
        4 => '0-15-10-5-0-0-0-0-0-0-0-0-0',
        5 => '0-25-15-8-3-0-0-0-0-0-0-0-0',
    ];

    /** Resource pool per camp level (crop-lumber-stone-iron-gold). */
    private const LEVEL_RESOURCES = [
        1 => '100-100-100-100-50',
        2 => '200-200-200-200-100',
        3 => '350-350-350-350-150',
        4 => '500-500-500-500-250',
        5 => '800-800-800-800-400',
    ];

    /** XP awarded to attacker on victory, per camp level. */
    private const LEVEL_XP = [
        1 => 30,
        2 => 60,
        3 => 100,
        4 => 150,
        5 => 250,
    ];

    public function __construct(\DB\SQL $db)
    {
        $this->db = $db;
    }

    /**
     * Spawn barbarian camps at random empty map tiles.
     * Returns the number of camps actually spawned.
     */
    public function spawnCamps(int $count = 5): int
    {
        $count = max(1, min(50, $count));

        // Build set of coordinates already occupied by active camps.
        $existing = $this->db->exec('SELECT x, y FROM barbarian_camps WHERE active = 1');
        $occupied = [];
        foreach (($existing ?: []) as $c) {
            $occupied[$c['x'] . ',' . $c['y']] = true;
        }

        // Pick random land/plains tiles (type 0 or 1 — no towns, no water).
        $tiles = $this->db->exec(
            'SELECT x, y FROM map WHERE type IN (0, 1) ORDER BY RAND() LIMIT 500'
        );

        $spawned = 0;
        foreach (($tiles ?: []) as $tile) {
            if ($spawned >= $count) {
                break;
            }
            $key = $tile['x'] . ',' . $tile['y'];
            if (isset($occupied[$key])) {
                continue;
            }

            $level = rand(1, 5);
            $army  = self::LEVEL_ARMIES[$level];
            $res   = self::LEVEL_RESOURCES[$level];

            $this->db->exec(
                'INSERT IGNORE INTO barbarian_camps (x, y, level, army, resources, weapons, active, respawn_at)
                 VALUES (?, ?, ?, ?, ?, ?, 1, NULL)',
                [(int) $tile['x'], (int) $tile['y'], $level, $army, $res, '0-0-0-0-0-0-0-0-0-0-0']
            );

            $occupied[$key] = true;
            $spawned++;
        }

        return $spawned;
    }

    /**
     * Return active camps visible in the map viewport (cx ± range, cy ± range).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getCampsForMap(int $cx, int $cy, int $range = 3): array
    {
        $rows = $this->db->exec(
            'SELECT id, x, y, level FROM barbarian_camps
             WHERE active = 1
               AND x BETWEEN ? AND ?
               AND y BETWEEN ? AND ?',
            [$cx - $range, $cx + $range, $cy - $range, $cy + $range]
        );

        return $rows ?: [];
    }

    /**
     * Return all camps (active + respawning) enriched for the list page.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAllCamps(): array
    {
        // Pre-load faction 1 unit names keyed by type index.
        $unitRows  = $this->db->exec('SELECT type, name FROM units WHERE faction = 1');
        $unitNames = [];
        foreach (($unitRows ?: []) as $u) {
            $unitNames[(int) $u['type']] = (string) $u['name'];
        }

        $rows = $this->db->exec(
            'SELECT id, x, y, level, army, resources, active, respawn_at
             FROM barbarian_camps
             ORDER BY active DESC, level DESC, id ASC'
        );

        $out = [];
        foreach (($rows ?: []) as $r) {
            $campArmy = DataParser::toIntArray($r['army']);
            $campRes  = DataParser::toIntArray($r['resources']);

            // Build per-unit list (only non-zero slots).
            $unitList = [];
            foreach ($campArmy as $idx => $cnt) {
                if ($cnt > 0) {
                    $unitList[] = [
                        'idx'   => $idx,
                        'count' => $cnt,
                        'name'  => $unitNames[$idx] ?? '',
                    ];
                }
            }

            $out[] = [
                'id'          => (int) $r['id'],
                'x'           => (int) $r['x'],
                'y'           => (int) $r['y'],
                'level'       => (int) $r['level'],
                'total_units' => array_sum($campArmy),
                'unit_list'   => $unitList,
                'crop'        => $campRes[0] ?? 0,
                'lumber'      => $campRes[1] ?? 0,
                'stone'       => $campRes[2] ?? 0,
                'iron'        => $campRes[3] ?? 0,
                'active'      => (bool) $r['active'],
                'respawn_at'  => (string) ($r['respawn_at'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Create a new barbarian camp at the given coordinates.
     * Returns the new camp ID, or 0 on failure.
     */
    public function createCamp(int $x, int $y, int $level): int
    {
        $level = max(1, min(5, $level));
        $army  = self::LEVEL_ARMIES[$level];
        $res   = self::LEVEL_RESOURCES[$level];

        $this->db->exec(
            'INSERT INTO barbarian_camps (x, y, level, army, resources, weapons, active, respawn_at)
             VALUES (?, ?, ?, ?, ?, ?, 1, NULL)',
            [$x, $y, $level, $army, $res, '0-0-0-0-0-0-0-0-0-0-0']
        );

        $row = $this->db->exec('SELECT LAST_INSERT_ID() AS id');
        return (int) ($row[0]['id'] ?? 0);
    }

    /**
     * Update an existing camp.
     */
    public function updateCamp(int $id, int $x, int $y, int $level, bool $active, string $army, string $resources): bool
    {
        $level = max(1, min(5, $level));
        $result = $this->db->exec(
            'UPDATE barbarian_camps SET x = ?, y = ?, level = ?, active = ?, army = ?, resources = ?, respawn_at = NULL
             WHERE id = ?',
            [$x, $y, $level, $active ? 1 : 0, $army, $resources, $id]
        );
        return $result > 0;
    }

    /**
     * Delete a camp permanently.
     */
    public function deleteCamp(int $id): void
    {
        $this->db->exec('DELETE FROM barbarian_camps WHERE id = ?', [$id]);
    }

    /**
     * Get a single camp by ID.
     *
     * @return array<string, mixed>|null
     */
    public function getCampById(int $id): ?array
    {
        $rows = $this->db->exec(
            'SELECT id, x, y, level, army, resources, active, respawn_at FROM barbarian_camps WHERE id = ? LIMIT 1',
            [$id]
        );
        return !empty($rows) ? $rows[0] : null;
    }

    /**
     * Mark a camp as defeated and schedule its respawn 24 hours later.
     */
    public function processDefeatedCamp(int $campId): void
    {
        $this->db->exec(
            'UPDATE barbarian_camps
             SET active = 0, respawn_at = DATE_ADD(NOW(), INTERVAL 24 HOUR)
             WHERE id = ?',
            [$campId]
        );
    }

    /**
     * Restore any camps whose respawn timer has elapsed.
     * Called from GameTickService every request.
     */
    public function respawnDueCamps(): void
    {
        $due = $this->db->exec(
            'SELECT id, level FROM barbarian_camps
             WHERE active = 0 AND respawn_at IS NOT NULL AND respawn_at <= NOW()'
        );

        foreach (($due ?: []) as $camp) {
            $level = max(1, min(5, (int) $camp['level']));
            $army  = self::LEVEL_ARMIES[$level];
            $res   = self::LEVEL_RESOURCES[$level];

            $this->db->exec(
                'UPDATE barbarian_camps
                 SET active = 1, army = ?, resources = ?, respawn_at = NULL
                 WHERE id = ?',
                [$army, $res, (int) $camp['id']]
            );
        }
    }

    /**
     * Process the arrival of an attacking army at a barbarian camp.
     * Mirrors BattleService::processArrival() for the camp case.
     *
     * @param  array<string, mixed> $movement  Row from a_queue
     * @param  int                  $campId    barbarian_camps.id
     * @return array{army: string, rLoot: string, wLoot: string, intel: string}
     */
    public function processArrival(array $movement, int $campId): array
    {
        $emptyReturn = [
            'army'  => $movement['army'],
            'rLoot' => '0-0-0-0-0',
            'wLoot' => '0-0-0-0-0-0-0-0-0-0-0',
            'intel' => '',
        ];

        $campRow = $this->db->exec(
            'SELECT * FROM barbarian_camps WHERE id = ? LIMIT 1',
            [$campId]
        );

        if (empty($campRow)) {
            return $emptyReturn;
        }
        $camp = $campRow[0];

        // Camp already defeated — army returns empty-handed.
        if (!(bool) $camp['active']) {
            return $emptyReturn;
        }

        // Determine attacker faction.
        $fromTownId = (int) $movement['town'];
        $townRow    = $this->db->exec(
            'SELECT owner FROM towns WHERE id = ? LIMIT 1',
            [$fromTownId]
        );
        $ownerId   = (int) ($townRow[0]['owner'] ?? 0);
        $userRow   = $this->db->exec(
            'SELECT faction FROM users WHERE id = ? LIMIT 1',
            [$ownerId]
        );
        $factionId = (int) ($userRow[0]['faction'] ?? 1);
        if ($factionId < 1) {
            $factionId = 1;
        }

        $atkArmy = DataParser::toIntArray($movement['army']);
        $defArmy = DataParser::toIntArray($camp['army']);

        $battleService = new BattleService($this->db);
        $result        = $battleService->simulateBattle($atkArmy, $defArmy, $factionId);

        $rLoot = '0-0-0-0-0';

        if ($result['attackerWins']) {
            // 60% of camp resources as loot.
            $campRes = DataParser::toIntArray($camp['resources']);
            $loot    = array_map(static fn(int $r): int => (int) ($r * 0.6), $campRes);
            $rLoot   = DataParser::serialize($loot);

            $this->processDefeatedCamp($campId);

            // Award XP to attacker.
            if ($ownerId > 0) {
                $level  = max(1, min(5, (int) $camp['level']));
                $xpGain = self::LEVEL_XP[$level];
                $this->db->exec(
                    'UPDATE users SET xp = xp + ? WHERE id = ?',
                    [$xpGain, $ownerId]
                );
            }
        } else {
            // Camp survived — persist reduced camp army.
            $this->db->exec(
                'UPDATE barbarian_camps SET army = ? WHERE id = ?',
                [DataParser::serialize($result['defArmy']), $campId]
            );
        }

        $this->sendCampBattleReport($camp, $result, $ownerId, $rLoot);

        return [
            'army'  => DataParser::serialize($result['atkArmy']),
            'rLoot' => $rLoot,
            'wLoot' => '0-0-0-0-0-0-0-0-0-0-0',
            'intel' => '',
        ];
    }

    /**
     * Insert a battle report for the attacker into the reports table.
     *
     * @param array<string, mixed> $camp
     * @param array<string, mixed> $battleResult
     */
    private function sendCampBattleReport(
        array  $camp,
        array  $battleResult,
        int    $ownerId,
        string $loot
    ): void {
        if ($ownerId <= 0) {
            return;
        }

        $outcome   = $battleResult['attackerWins'] ? 'Victory' : 'Defeat';
        $campLevel = (int) $camp['level'];
        $subject   = "Barbarian Camp Lv.{$campLevel} — {$outcome}";

        $atkLost = 0;
        foreach (($battleResult['atkBefore'] ?? []) as $idx => $cnt) {
            $atkLost += max(0, $cnt - ($battleResult['atkArmy'][$idx] ?? 0));
        }

        $lp     = DataParser::toIntArray($loot);
        $lootTxt = "Crop {$lp[0]}, Lumber {$lp[1]}, Stone {$lp[2]}, Iron {$lp[3]}";

        $contents = "Camp at ({$camp['x']},{$camp['y']}) — Level {$campLevel}.\n"
            . "Outcome: {$outcome}.\n"
            . "Your losses: {$atkLost} units.\n"
            . "Loot: {$lootTxt}.";

        try {
            $this->db->exec(
                'INSERT INTO reports (recipient, subject, contents, sent) VALUES (?, ?, ?, NOW())',
                [$ownerId, $subject, $contents]
            );
        } catch (\Throwable $e) {
            // Non-critical — don't break the battle flow.
        }
    }
}

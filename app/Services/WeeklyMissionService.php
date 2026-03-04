<?php declare(strict_types=1);

namespace Devana\Services;

use Devana\Helpers\DataParser;

final class WeeklyMissionService
{
    private \DB\SQL $db;

    // Reuse MissionService type constants
    public const TYPE_BUILD  = 0;
    public const TYPE_TRAIN  = 1;
    public const TYPE_RAID   = 2;
    public const TYPE_TRADE  = 3;
    public const TYPE_GOLD   = 4;

    // Weekly targets (much larger than daily)
    private const TARGET_RANGES = [
        self::TYPE_BUILD  => [5, 10],
        self::TYPE_TRAIN  => [50, 100],
        self::TYPE_RAID   => [3, 7],
        self::TYPE_TRADE  => [5, 10],
        self::TYPE_GOLD   => [500, 2000],
    ];

    private const XP_REWARDS = [
        self::TYPE_BUILD  => 500,
        self::TYPE_TRAIN  => 500,
        self::TYPE_RAID   => 750,
        self::TYPE_TRADE  => 500,
        self::TYPE_GOLD   => 500,
    ];

    private const RESOURCE_REWARD = [
        self::TYPE_BUILD  => [0, 1000],  // [index, amount]
        self::TYPE_TRAIN  => [1, 1000],
        self::TYPE_RAID   => [3, 1000],
        self::TYPE_TRADE  => [2, 1000],
        self::TYPE_GOLD   => [4, 1000],
    ];

    public function __construct(\DB\SQL $db)
    {
        $this->db = $db;
    }

    /**
     * Return this week's missions for a user, generating them if none exist yet.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getWeeklyMissions(int $userId): array
    {
        $weekStart = $this->getWeekStart();
        $rows = $this->fetchWeeklyMissions($userId, $weekStart);

        if (count($rows) < 3) {
            $this->generateWeekly($userId);
            $rows = $this->fetchWeeklyMissions($userId, $weekStart);
        }

        return $rows;
    }

    public function generateWeekly(int $userId): void
    {
        $weekStart = $this->getWeekStart();

        $existing = $this->db->exec(
            'SELECT type FROM weekly_missions WHERE user_id = ? AND week_start = ?',
            [$userId, $weekStart]
        );
        $existingTypes = array_map(fn($r) => (int) $r['type'], $existing);

        $allTypes = [self::TYPE_BUILD, self::TYPE_TRAIN, self::TYPE_RAID, self::TYPE_TRADE, self::TYPE_GOLD];
        $available = array_diff($allTypes, $existingTypes);
        shuffle($available);
        $toCreate = array_slice(array_values($available), 0, 3 - count($existingTypes));

        foreach ($toCreate as $type) {
            [$min, $max] = self::TARGET_RANGES[$type];
            $target = rand($min, $max);
            $this->db->exec(
                'INSERT IGNORE INTO weekly_missions (user_id, week_start, type, target, progress, claimed) VALUES (?, ?, ?, ?, 0, 0)',
                [$userId, $weekStart, $type, $target]
            );
        }
    }

    public function incrementProgress(int $userId, int $type, int $amount = 1): void
    {
        $weekStart = $this->getWeekStart();
        $this->db->exec(
            'UPDATE weekly_missions
             SET progress = LEAST(target, progress + ?)
             WHERE user_id = ? AND week_start = ? AND type = ? AND claimed = 0',
            [$amount, $userId, $weekStart, $type]
        );
    }

    /**
     * @return array{success?: bool, xp?: int, error_key?: string}
     */
    public function claimReward(int $userId, int $missionId): array
    {
        $rows = $this->db->exec(
            'SELECT * FROM weekly_missions WHERE id = ? AND user_id = ?',
            [$missionId, $userId]
        );

        if (empty($rows)) {
            return ['error_key' => 'missionNotFound'];
        }

        $mission = $rows[0];

        if ((int) $mission['claimed'] === 1) {
            return ['error_key' => 'rewardAlreadyClaimed'];
        }

        if ((int) $mission['progress'] < (int) $mission['target']) {
            return ['error_key' => 'missionNotComplete'];
        }

        $type = (int) $mission['type'];
        $xp = self::XP_REWARDS[$type] ?? 500;
        [$resIdx, $resAmount] = self::RESOURCE_REWARD[$type] ?? [0, 1000];

        // Grant XP
        $this->db->exec('UPDATE users SET xp = xp + ? WHERE id = ?', [$xp, $userId]);

        // Grant resources
        $townRow = $this->db->exec(
            'SELECT id, resources, `limits` FROM towns WHERE owner = ? ORDER BY id ASC LIMIT 1',
            [$userId]
        );
        if (!empty($townRow)) {
            $res = DataParser::toFloatArray($townRow[0]['resources']);
            $lim = DataParser::toFloatArray($townRow[0]['limits']);
            $limitIdx = [0 => 0, 1 => 1, 2 => 1, 3 => 1, 4 => 2];
            $cap = $lim[$limitIdx[$resIdx] ?? 1] ?? PHP_FLOAT_MAX;
            $res[$resIdx] = min(($res[$resIdx] ?? 0) + $resAmount, $cap);
            $this->db->exec(
                'UPDATE towns SET resources = ? WHERE id = ?',
                [DataParser::serializeRounded($res), (int) $townRow[0]['id']]
            );
        }

        // Mark claimed
        $this->db->exec(
            'UPDATE weekly_missions SET claimed = 1 WHERE id = ?',
            [$missionId]
        );

        // Season score +10 for weekly mission claim
        try {
            $seasonService = new SeasonService($this->db);
            $seasonService->addScore($userId, 10, 'weekly_mission');
        } catch (\Throwable $e) {
            // non-critical
        }

        return ['success' => true, 'xp' => $xp];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchWeeklyMissions(int $userId, string $weekStart): array
    {
        return $this->db->exec(
            'SELECT * FROM weekly_missions WHERE user_id = ? AND week_start = ? ORDER BY type ASC',
            [$userId, $weekStart]
        ) ?: [];
    }

    private function getWeekStart(): string
    {
        // Monday of current week
        return date('Y-m-d', strtotime('monday this week'));
    }
}

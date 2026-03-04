<?php declare(strict_types=1);

namespace Devana\Services;

use Devana\Helpers\DataParser;
use Devana\Services\SeasonService;

final class MissionService
{
    private \DB\SQL $db;
    private ?bool $hasTemplateTable = null;

    // Mission type constants
    public const TYPE_BUILD  = 0;
    public const TYPE_TRAIN  = 1;
    public const TYPE_RAID   = 2;
    public const TYPE_TRADE  = 3;
    public const TYPE_GOLD   = 4;

    // XP rewards per mission type
    private const XP_REWARDS = [
        self::TYPE_BUILD  => 100,
        self::TYPE_TRAIN  => 100,
        self::TYPE_RAID   => 150,
        self::TYPE_TRADE  => 100,
        self::TYPE_GOLD   => 100,
    ];

    // Resource index for reward (maps to resources array: 0=crop,1=lumber,2=stone,3=iron,4=gold)
    private const RESOURCE_REWARD_INDEX = [
        self::TYPE_BUILD  => 0, // crop
        self::TYPE_TRAIN  => 1, // lumber
        self::TYPE_RAID   => 3, // iron
        self::TYPE_TRADE  => 2, // stone
        self::TYPE_GOLD   => 4, // gold
    ];

    // Target ranges per type [min, max]
    private const TARGET_RANGES = [
        self::TYPE_BUILD  => [1, 3],
        self::TYPE_TRAIN  => [5, 20],
        self::TYPE_RAID   => [1, 3],
        self::TYPE_TRADE  => [1, 3],
        self::TYPE_GOLD   => [50, 200],
    ];

    public function __construct(\DB\SQL $db)
    {
        $this->db = $db;
    }

    /**
     * Return today's missions for a user, generating them if none exist yet.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getDailyMissions(int $userId): array
    {
        $today = date('Y-m-d');
        $rows = $this->fetchDailyMissions($userId, $today);

        if (count($rows) < 3) {
            $this->generateMissionsForToday($userId);
            $rows = $this->fetchDailyMissions($userId, $today);
        }

        return $rows;
    }

    /**
     * Generate 3 random daily missions for a user (skipping already-existing types).
     */
    public function generateMissionsForToday(int $userId): void
    {
        $today = date('Y-m-d');

        $existing = $this->db->exec(
            'SELECT type FROM player_missions WHERE user = ? AND date = ?',
            [$userId, $today]
        );
        $existingTypes = array_map(fn($r) => (int) $r['type'], $existing);

        if ($this->templatesEnabled()) {
            $templates = $this->activeTemplates();
            shuffle($templates);
            foreach ($templates as $tpl) {
                if (count($existingTypes) >= 3) {
                    break;
                }
                $type = (int) ($tpl['type'] ?? -1);
                if (!in_array($type, self::allTypes(), true) || in_array($type, $existingTypes, true)) {
                    continue;
                }

                $min = max(1, (int) ($tpl['target_min'] ?? 1));
                $max = max($min, (int) ($tpl['target_max'] ?? $min));
                $target = rand($min, $max);

                $this->db->exec(
                    'INSERT IGNORE INTO player_missions (user, date, type, template_id, target, progress, claimed) VALUES (?, ?, ?, ?, ?, 0, 0)',
                    [$userId, $today, $type, (int) ($tpl['id'] ?? 0), $target]
                );
                $existingTypes[] = $type;
            }
        }

        if (count($existingTypes) < 3) {
            $available = array_diff(self::allTypes(), $existingTypes);
            shuffle($available);
            $toCreate = array_slice(array_values($available), 0, 3 - count($existingTypes));

            foreach ($toCreate as $type) {
                [$min, $max] = self::TARGET_RANGES[$type];
                $target = rand($min, $max);
                $this->db->exec(
                    'INSERT IGNORE INTO player_missions (user, date, type, target, progress, claimed) VALUES (?, ?, ?, ?, 0, 0)',
                    [$userId, $today, $type, $target]
                );
            }
        }
    }

    /**
     * Increment progress on today's active mission of the given type.
     */
    public function incrementProgress(int $userId, int $type, int $amount = 1): void
    {
        $today = date('Y-m-d');
        $this->db->exec(
            'UPDATE player_missions
             SET progress = LEAST(target, progress + ?)
             WHERE user = ? AND date = ? AND type = ? AND claimed = 0',
            [$amount, $userId, $today, $type]
        );
    }

    /**
     * Claim the reward for a completed mission.
     *
     * @return array{success?: bool, xp?: int, error?: string}
     */
    public function claimReward(int $userId, int $missionId): array
    {
        $rows = $this->db->exec(
            'SELECT pm.*, mt.reward_xp, mt.reward_resource_index, mt.reward_resource_amount
             FROM player_missions pm
             LEFT JOIN mission_templates mt ON mt.id = pm.template_id
             WHERE pm.id = ? AND pm.user = ?',
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
        $xp = isset($mission['reward_xp']) && $mission['reward_xp'] !== null
            ? max(0, (int) $mission['reward_xp'])
            : (self::XP_REWARDS[$type] ?? 100);
        $resIdx = isset($mission['reward_resource_index']) && $mission['reward_resource_index'] !== null
            ? max(0, min(4, (int) $mission['reward_resource_index']))
            : (self::RESOURCE_REWARD_INDEX[$type] ?? 0);
        $resAmount = isset($mission['reward_resource_amount']) && $mission['reward_resource_amount'] !== null
            ? max(0, (int) $mission['reward_resource_amount'])
            : 200;

        // Grant XP
        $this->db->exec('UPDATE users SET xp = xp + ? WHERE id = ?', [$xp, $userId]);

        // Grant 200 of the appropriate resource to the player's first town
        $townRow = $this->db->exec(
            'SELECT id, resources FROM towns WHERE owner = ? ORDER BY id ASC LIMIT 1',
            [$userId]
        );
        if (!empty($townRow)) {
            $res = DataParser::toFloatArray($townRow[0]['resources']);
            $res[$resIdx] = ($res[$resIdx] ?? 0) + $resAmount;
            $this->db->exec(
                'UPDATE towns SET resources = ? WHERE id = ?',
                [DataParser::serializeRounded($res), (int) $townRow[0]['id']]
            );
        }

        // Mark claimed
        $this->db->exec(
            'UPDATE player_missions SET claimed = 1 WHERE id = ?',
            [$missionId]
        );

        // Season score +3 for daily mission claim
        try {
            (new SeasonService($this->db))->addScore($userId, SeasonService::SCORE_DAILY_CLAIM, 'daily_mission');
        } catch (\Throwable $e) {
            // non-critical
        }

        return ['success' => true, 'xp' => $xp];
    }

    /**
     * Calculate player level from XP (1-based: level 1 = 0 XP).
     * Formula: floor(sqrt(xp / 100)) + 1
     */
    public static function getXpLevel(int $xp): int
    {
        return (int) floor(sqrt($xp / 100)) + 1;
    }

    /**
     * @return list<int>
     */
    public static function allTypes(): array
    {
        return [
            self::TYPE_BUILD,
            self::TYPE_TRAIN,
            self::TYPE_RAID,
            self::TYPE_TRADE,
            self::TYPE_GOLD,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchDailyMissions(int $userId, string $date): array
    {
        if ($this->templatesEnabled()) {
            return $this->db->exec(
                'SELECT pm.*,
                        mt.title_tr AS template_title_tr,
                        mt.title_en AS template_title_en,
                        mt.reward_xp AS template_reward_xp,
                        mt.reward_resource_index AS template_reward_resource_index,
                        mt.reward_resource_amount AS template_reward_resource_amount
                 FROM player_missions pm
                 LEFT JOIN mission_templates mt ON mt.id = pm.template_id
                 WHERE pm.user = ? AND pm.date = ?
                 ORDER BY pm.type ASC',
                [$userId, $date]
            );
        }

        return $this->db->exec(
            'SELECT * FROM player_missions WHERE user = ? AND date = ? ORDER BY type ASC',
            [$userId, $date]
        );
    }

    private function templatesEnabled(): bool
    {
        if ($this->hasTemplateTable !== null) {
            return $this->hasTemplateTable;
        }

        $rows = $this->db->exec("SHOW TABLES LIKE 'mission_templates'");
        $this->hasTemplateTable = !empty($rows);
        if ($this->hasTemplateTable) {
            $this->ensureDefaultTemplates();
        }

        return $this->hasTemplateTable;
    }

    private function ensureDefaultTemplates(): void
    {
        $count = (int) ($this->db->exec('SELECT COUNT(*) AS cnt FROM mission_templates')[0]['cnt'] ?? 0);
        if ($count > 0) {
            return;
        }

        $defaults = [
            [self::TYPE_BUILD, 'Tamamla {n} insaat', 'Complete {n} constructions', 1, 3, 100, 0, 200, 1],
            [self::TYPE_TRAIN, 'Egit {n} birlik', 'Train {n} units', 5, 20, 100, 1, 200, 2],
            [self::TYPE_RAID, 'Gonder {n} yagma', 'Send {n} raids', 1, 3, 150, 3, 200, 3],
            [self::TYPE_TRADE, '{n} ticaret yap', 'Make {n} trades', 1, 3, 100, 2, 200, 4],
            [self::TYPE_GOLD, '{n} altin topla', 'Collect {n} gold', 50, 200, 100, 4, 200, 5],
        ];

        foreach ($defaults as $d) {
            $this->db->exec(
                'INSERT INTO mission_templates
                 (type, title_tr, title_en, target_min, target_max, reward_xp, reward_resource_index, reward_resource_amount, is_active, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)',
                $d
            );
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function activeTemplates(): array
    {
        return $this->db->exec(
            'SELECT * FROM mission_templates
             WHERE is_active = 1
             ORDER BY sort_order ASC, id ASC'
        ) ?: [];
    }


}

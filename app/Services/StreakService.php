<?php declare(strict_types=1);

namespace Devana\Services;

use Devana\Helpers\DataParser;

final class StreakService
{
    private \DB\SQL $db;

    // Milestone days => [resource_amount, xp, title_key]
    private const MILESTONES = [
        3  => ['resources' => 200,  'xp' => 0,   'key' => 'streak3'],
        7  => ['resources' => 500,  'xp' => 50,  'key' => 'streak7'],
        14 => ['resources' => 1000, 'xp' => 100, 'key' => 'streak14'],
        30 => ['resources' => 2000, 'xp' => 500, 'key' => 'streak30'],
        60 => ['resources' => 5000, 'xp' => 1000,'key' => 'streak60'],
        100=> ['resources' => 10000,'xp' => 2000,'key' => 'streak100'],
    ];

    public function __construct(\DB\SQL $db)
    {
        $this->db = $db;
    }

    /**
     * Check login date and update streak.
     * Returns ['streak' => N, 'reward' => [...] | null, 'is_new_day' => bool]
     *
     * @return array{streak: int, reward: array<string,mixed>|null, is_new_day: bool}
     */
    public function checkAndUpdate(int $userId, ?string $lastLoginDate): array
    {
        $today = date('Y-m-d');

        if ($lastLoginDate === $today) {
            // Already logged in today
            $row = $this->db->exec('SELECT login_streak FROM users WHERE id = ? LIMIT 1', [$userId]);
            return [
                'streak' => (int) ($row[0]['login_streak'] ?? 0),
                'reward' => null,
                'is_new_day' => false,
            ];
        }

        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $row = $this->db->exec(
            'SELECT login_streak, max_streak FROM users WHERE id = ? LIMIT 1',
            [$userId]
        );
        $currentStreak = (int) ($row[0]['login_streak'] ?? 0);
        $maxStreak = (int) ($row[0]['max_streak'] ?? 0);

        if ($lastLoginDate === $yesterday) {
            // Consecutive day
            $newStreak = $currentStreak + 1;
        } else {
            // Streak broken
            $newStreak = 1;
        }

        $newMax = max($maxStreak, $newStreak);

        $this->db->exec(
            'UPDATE users SET login_streak = ?, last_login_date = ?, max_streak = ? WHERE id = ?',
            [$newStreak, $today, $newMax, $userId]
        );

        $reward = $this->getStreakReward($newStreak);

        if ($reward !== null) {
            $this->grantReward($userId, $reward);
        }

        return [
            'streak' => $newStreak,
            'reward' => $reward,
            'is_new_day' => true,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getStreakReward(int $streak): ?array
    {
        return self::MILESTONES[$streak] ?? null;
    }

    /**
     * @param array<string, mixed> $reward
     */
    private function grantReward(int $userId, array $reward): void
    {
        try {
            $xp = (int) ($reward['xp'] ?? 0);
            $resources = (int) ($reward['resources'] ?? 0);

            if ($xp > 0) {
                $this->db->exec('UPDATE users SET xp = xp + ? WHERE id = ?', [$xp, $userId]);
            }

            if ($resources > 0) {
                $townRow = $this->db->exec(
                    'SELECT id, resources, `limits` FROM towns WHERE owner = ? ORDER BY id ASC LIMIT 1',
                    [$userId]
                );
                if (!empty($townRow)) {
                    $res = DataParser::toFloatArray($townRow[0]['resources']);
                    $lim = DataParser::toFloatArray($townRow[0]['limits']);
                    // Distribute resources evenly across crop, lumber, stone, iron (indices 0-3)
                    $perRes = (int) ($resources / 4);
                    $limitIdx = [0 => 0, 1 => 1, 2 => 1, 3 => 1];
                    for ($i = 0; $i <= 3; $i++) {
                        $cap = $lim[$limitIdx[$i] ?? 1] ?? PHP_FLOAT_MAX;
                        $res[$i] = min(($res[$i] ?? 0) + $perRes, $cap);
                    }
                    $this->db->exec(
                        'UPDATE towns SET resources = ? WHERE id = ?',
                        [DataParser::serializeRounded($res), (int) $townRow[0]['id']]
                    );
                }
            }

            // Trigger achievement for streak milestones
            try {
                $achievementService = new AchievementService($this->db);
                $totalRow = $this->db->exec('SELECT login_streak FROM users WHERE id = ? LIMIT 1', [$userId]);
                $streak = (int) ($totalRow[0]['login_streak'] ?? 0);
                if ($streak >= 7) {
                    $achievementService->check($userId, 'streak_7', $streak);
                }
                if ($streak >= 30) {
                    $achievementService->check($userId, 'streak_30', $streak);
                }
            } catch (\Throwable $e) {
                // non-critical
            }
        } catch (\Throwable $e) {
            // non-critical
        }
    }
}

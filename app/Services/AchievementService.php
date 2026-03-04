<?php declare(strict_types=1);

namespace Devana\Services;

final class AchievementService
{
    private \DB\SQL $db;

    // Category constants
    public const CAT_BUILD   = 0;
    public const CAT_MILITARY = 1;
    public const CAT_TRADE   = 2;
    public const CAT_SOCIAL  = 3;
    public const CAT_EXPLORE = 4;

    private const DEFAULT_ACHIEVEMENTS = [
        ['first_build',       'İlk İnşaat',       'First Construction',    '',   '', self::CAT_BUILD,    1,  50,  1],
        ['builder_10',        'Usta İnşaatçı',     'Master Builder',        '',   '', self::CAT_BUILD,    10, 200, 2],
        ['builder_50',        'Mühendis',          'Engineer',              '',   '', self::CAT_BUILD,    50, 500, 3],
        ['first_raid',        'İlk Baskın',        'First Raid',            '',   '', self::CAT_MILITARY, 1,  50,  4],
        ['veteran_10_raids',  'Savaşçı',           'Warrior',               '',   '', self::CAT_MILITARY, 10, 200, 5],
        ['veteran_50_raids',  'Veteran',           'Veteran',               '',   '', self::CAT_MILITARY, 50, 500, 6],
        ['trader_10',         'Tüccar',            'Trader',                '',   '', self::CAT_TRADE,    10, 200, 7],
        ['trader_50',         'Büyük Tüccar',      'Grand Trader',          '',   '', self::CAT_TRADE,    50, 500, 8],
        ['streak_7',          'Sadık',             'Loyal',                 '',   '', self::CAT_SOCIAL,   7,  200, 9],
        ['streak_30',         'Efsane',            'Legend',                '',   '', self::CAT_SOCIAL,   30, 1000,10],
        ['first_train',       'Komutan',           'Commander',             '',   '', self::CAT_MILITARY, 1,  50,  11],
        ['train_100',         'Ordu Kurucusu',     'Army Founder',          '',   '', self::CAT_MILITARY, 100,300, 12],
    ];

    public function __construct(\DB\SQL $db)
    {
        $this->db = $db;
    }

    /**
     * Check if a threshold achievement should be granted.
     */
    public function check(int $userId, string $code, int $currentValue): void
    {
        if ($userId <= 0) {
            return;
        }

        try {
            $achRow = $this->db->exec(
                'SELECT id, threshold, reward_xp FROM achievements WHERE code = ? AND is_active = 1 LIMIT 1',
                [$code]
            );

            if (empty($achRow)) {
                return;
            }

            $ach = $achRow[0];
            $threshold = (int) $ach['threshold'];
            $achId = (int) $ach['id'];

            if ($currentValue < $threshold) {
                return;
            }

            // Check if already earned
            $existing = $this->db->exec(
                'SELECT 1 FROM player_achievements WHERE user_id = ? AND achievement_id = ? LIMIT 1',
                [$userId, $achId]
            );

            if (!empty($existing)) {
                return;
            }

            $this->db->exec(
                'INSERT IGNORE INTO player_achievements (user_id, achievement_id, earned_at, claimed)
                 VALUES (?, ?, NOW(), 0)',
                [$userId, $achId]
            );

            // Grant XP immediately
            $rewardXp = (int) ($ach['reward_xp'] ?? 0);
            if ($rewardXp > 0) {
                $this->db->exec('UPDATE users SET xp = xp + ? WHERE id = ?', [$rewardXp, $userId]);
            }

            // Send notification
            try {
                $notif = new NotificationService($this->db);
                $notif->notify($userId, NotificationService::TYPE_ACHIEVEMENT, 'notifAchievement', '/profile/' . $userId);
            } catch (\Throwable $e) {
                // non-critical
            }
        } catch (\Throwable $e) {
            // non-critical
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getUserAchievements(int $userId): array
    {
        return $this->db->exec(
            'SELECT a.*, pa.earned_at, pa.claimed
             FROM achievements a
             INNER JOIN player_achievements pa ON pa.achievement_id = a.id
             WHERE pa.user_id = ?
             ORDER BY a.category ASC, a.sort_order ASC',
            [$userId]
        ) ?: [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAllAchievements(): array
    {
        return $this->db->exec(
            'SELECT * FROM achievements ORDER BY category ASC, sort_order ASC'
        ) ?: [];
    }

    public function ensureDefaults(): void
    {
        $count = (int) ($this->db->exec('SELECT COUNT(*) AS cnt FROM achievements')[0]['cnt'] ?? 0);
        if ($count > 0) {
            return;
        }

        foreach (self::DEFAULT_ACHIEVEMENTS as $d) {
            $this->db->exec(
                'INSERT IGNORE INTO achievements
                 (code, title_tr, title_en, desc_tr, desc_en, category, threshold, reward_xp, is_active, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)',
                $d
            );
        }
    }

    public function create(string $code, string $titleTr, string $titleEn, string $descTr, string $descEn, int $category, int $threshold, int $rewardXp, int $sortOrder = 0): bool
    {
        try {
            $this->db->exec(
                'INSERT INTO achievements (code, title_tr, title_en, desc_tr, desc_en, category, threshold, reward_xp, is_active, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)',
                [$code, $titleTr, $titleEn, $descTr, $descEn, $category, $threshold, $rewardXp, $sortOrder]
            );
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function update(int $id, string $titleTr, string $titleEn, string $descTr, string $descEn, int $category, int $threshold, int $rewardXp, int $isActive, int $sortOrder): void
    {
        $this->db->exec(
            'UPDATE achievements SET title_tr=?, title_en=?, desc_tr=?, desc_en=?, category=?, threshold=?, reward_xp=?, is_active=?, sort_order=? WHERE id=?',
            [$titleTr, $titleEn, $descTr, $descEn, $category, $threshold, $rewardXp, $isActive, $sortOrder, $id]
        );
    }

    public function delete(int $id): void
    {
        $this->db->exec('DELETE FROM player_achievements WHERE achievement_id = ?', [$id]);
        $this->db->exec('DELETE FROM achievements WHERE id = ?', [$id]);
    }
}

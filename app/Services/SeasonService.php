<?php declare(strict_types=1);

namespace Devana\Services;

final class SeasonService
{
    private \DB\SQL $db;

    // Score reasons
    public const SCORE_BUILD        = 1;
    public const SCORE_TRAIN        = 1;
    public const SCORE_RAID_WIN     = 5;
    public const SCORE_ATTACK_WIN   = 10;
    public const SCORE_TRADE        = 2;
    public const SCORE_DAILY_CLAIM  = 3;
    public const SCORE_WEEKLY_CLAIM = 10;

    public function __construct(\DB\SQL $db)
    {
        $this->db = $db;
    }

    public function getActiveSeason(): ?array
    {
        $rows = $this->db->exec(
            'SELECT * FROM seasons WHERE active = 1 LIMIT 1'
        );
        return !empty($rows) ? $rows[0] : null;
    }

    public function addScore(int $userId, int $points, string $reason = ''): void
    {
        if ($userId <= 0 || $points <= 0) {
            return;
        }

        try {
            $season = $this->getActiveSeason();
            if ($season === null) {
                return;
            }

            $seasonId = (int) $season['id'];
            $this->db->exec(
                'INSERT INTO season_scores (season_id, user_id, score) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE score = score + VALUES(score)',
                [$seasonId, $userId, $points]
            );
        } catch (\Throwable $e) {
            // non-critical
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getLeaderboard(int $seasonId, int $limit = 20): array
    {
        return $this->db->exec(
            'SELECT ss.user_id, ss.score, u.name AS username, u.points
             FROM season_scores ss
             LEFT JOIN users u ON u.id = ss.user_id
             WHERE ss.season_id = ?
             ORDER BY ss.score DESC
             LIMIT ?',
            [$seasonId, $limit]
        ) ?: [];
    }

    public function processSeasonEnd(int $seasonId): void
    {
        $leaderboard = $this->getLeaderboard($seasonId, 100);
        $titles = [
            1 => 'Sezon Şampiyonu',
            2 => 'Sezon İkincisi',
            3 => 'Sezon Üçüncüsü',
        ];

        foreach ($leaderboard as $rank => $row) {
            $pos = $rank + 1;
            $title = $titles[$pos] ?? 'Sezon Oyuncusu';
            $username = (string) ($row['username'] ?? 'Unknown');

            $this->db->exec(
                'INSERT INTO season_hall_of_fame (season_id, user_id, rank_pos, score, username, title)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$seasonId, (int) $row['user_id'], $pos, (int) $row['score'], $username, $title]
            );

            // Top 3 reward bonus XP
            if ($pos <= 3) {
                $xpBonus = [1 => 2000, 2 => 1000, 3 => 500][$pos] ?? 0;
                $this->db->exec(
                    'UPDATE users SET xp = xp + ? WHERE id = ?',
                    [$xpBonus, (int) $row['user_id']]
                );

                // Notify winners
                try {
                    $notif = new NotificationService($this->db);
                    $notif->notify((int) $row['user_id'], NotificationService::TYPE_SEASON_END, 'notifSeasonEnd', '/season/hall-of-fame');
                } catch (\Throwable $e) {
                    // non-critical
                }
            }
        }

        $this->db->exec(
            'UPDATE seasons SET reset_done = 1, active = 0 WHERE id = ?',
            [$seasonId]
        );
    }

    public function createSeason(string $name, string $startTime, string $endTime): int
    {
        $this->db->exec(
            'INSERT INTO seasons (name, start_time, end_time, active, reset_done) VALUES (?, ?, ?, 0, 0)',
            [$name, $startTime, $endTime]
        );
        $rows = $this->db->exec('SELECT LAST_INSERT_ID() AS id');
        return (int) ($rows[0]['id'] ?? 0);
    }

    public function activateSeason(int $seasonId): void
    {
        $this->db->exec('UPDATE seasons SET active = 0');
        $this->db->exec('UPDATE seasons SET active = 1 WHERE id = ?', [$seasonId]);
    }

    public function forceEndSeason(int $seasonId): void
    {
        $this->processSeasonEnd($seasonId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAllSeasons(): array
    {
        return $this->db->exec('SELECT * FROM seasons ORDER BY id DESC') ?: [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getHallOfFame(?int $seasonId = null): array
    {
        if ($seasonId !== null) {
            return $this->db->exec(
                'SELECT hof.*, s.name AS season_name
                 FROM season_hall_of_fame hof
                 LEFT JOIN seasons s ON s.id = hof.season_id
                 WHERE hof.season_id = ?
                 ORDER BY hof.rank_pos ASC',
                [$seasonId]
            ) ?: [];
        }

        return $this->db->exec(
            'SELECT hof.*, s.name AS season_name
             FROM season_hall_of_fame hof
             LEFT JOIN seasons s ON s.id = hof.season_id
             ORDER BY hof.season_id DESC, hof.rank_pos ASC
             LIMIT 100'
        ) ?: [];
    }

    public function resetSeasonData(): void
    {
        // Reset all player towns: resources, buildings, army (but NOT profile/achievements/gems)
        $this->db->exec(
            "UPDATE towns SET
             buildings = '0-0-0-0-0-0-0-0-0-0-0-0-0-0-0-0-0-0-0-0-0-0',
             army = '0-0-0-0-0-0-0-0-0-0-0-0-0',
             resources = '500-500-500-500-500',
             land = '0-0-0-0-0/0-0-0-0-0/0-0-0-0-0/0-0-0-0-0',
             production = '0-0-0-0-0',
             `limits` = '800-600-500-0-0-0-0-0-0-0-0-0-0',
             upkeep = 0
             WHERE owner > 0"
        );

        // Clear all queues
        $this->db->exec('DELETE FROM c_queue WHERE 1');
        $this->db->exec('DELETE FROM u_queue WHERE 1');
        $this->db->exec('DELETE FROM w_queue WHERE 1');
        $this->db->exec('DELETE FROM uup_queue WHERE 1');
        $this->db->exec('DELETE FROM a_queue WHERE 1');
        $this->db->exec('DELETE FROM t_queue WHERE 1');
    }
}

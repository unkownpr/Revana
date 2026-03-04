<?php declare(strict_types=1);

namespace Devana\Services;

final class AllianceWarService
{
    private \DB\SQL $db;

    // War status
    public const STATUS_PENDING = 0;
    public const STATUS_ACTIVE  = 1;
    public const STATUS_ENDED   = 2;

    public function __construct(\DB\SQL $db)
    {
        $this->db = $db;
    }

    /**
     * Declare war between two alliances.
     * @return array{success?: bool, error?: string}
     */
    public function declare(int $challengerId, int $defenderId, int $durationDays = 7): array
    {
        if ($challengerId === $defenderId) {
            return ['error' => 'Cannot declare war on your own alliance.'];
        }

        // Check both alliances exist
        $challenger = $this->db->exec('SELECT id FROM alliances WHERE id = ? LIMIT 1', [$challengerId]);
        $defender   = $this->db->exec('SELECT id FROM alliances WHERE id = ? LIMIT 1', [$defenderId]);

        if (empty($challenger) || empty($defender)) {
            return ['error' => 'Alliance not found.'];
        }

        // Check no active war
        $existing = $this->getActiveWar($challengerId);
        if ($existing !== null) {
            return ['error' => 'Your alliance is already in an active war.'];
        }

        $existing2 = $this->getActiveWar($defenderId);
        if ($existing2 !== null) {
            return ['error' => 'Defender alliance is already in an active war.'];
        }

        $endTime = date('Y-m-d H:i:s', strtotime('+' . $durationDays . ' days'));

        $this->db->exec(
            'INSERT INTO alliance_wars (challenger_id, defender_id, start_time, end_time, status, challenger_score, defender_score)
             VALUES (?, ?, NOW(), ?, ?, 0, 0)',
            [$challengerId, $defenderId, $endTime, self::STATUS_ACTIVE]
        );

        $warId = (int) ($this->db->exec('SELECT LAST_INSERT_ID() AS id')[0]['id'] ?? 0);

        // Notify defender alliance members
        try {
            $notif = new NotificationService($this->db);
            $defMembers = $this->db->exec('SELECT id FROM users WHERE alliance = ? LIMIT 100', [$defenderId]);
            foreach ($defMembers as $member) {
                $notif->notify((int) $member['id'], NotificationService::TYPE_WAR_DECLARED, 'notifWarDeclared', '/alliance/' . $defenderId);
            }
        } catch (\Throwable $e) {
            // non-critical
        }

        return ['success' => true, 'war_id' => $warId];
    }

    public function getActiveWar(int $allianceId): ?array
    {
        $rows = $this->db->exec(
            'SELECT * FROM alliance_wars
             WHERE (challenger_id = ? OR defender_id = ?) AND status = ?
             LIMIT 1',
            [$allianceId, $allianceId, self::STATUS_ACTIVE]
        );
        return !empty($rows) ? $rows[0] : null;
    }

    public function addScore(int $warId, int $userId, int $allianceId, int $points): void
    {
        if ($points <= 0) {
            return;
        }

        try {
            $this->db->exec(
                'INSERT INTO alliance_war_scores (war_id, user_id, alliance_id, score) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE score = score + VALUES(score)',
                [$warId, $userId, $allianceId, $points]
            );

            // Update war totals
            $war = $this->db->exec('SELECT challenger_id, defender_id FROM alliance_wars WHERE id = ? LIMIT 1', [$warId]);
            if (!empty($war)) {
                $col = ((int) $war[0]['challenger_id'] === $allianceId) ? 'challenger_score' : 'defender_score';
                $this->db->exec(
                    "UPDATE alliance_wars SET {$col} = {$col} + ? WHERE id = ?",
                    [$points, $warId]
                );
            }
        } catch (\Throwable $e) {
            // non-critical
        }
    }

    public function processWarEnd(int $warId): void
    {
        $war = $this->db->exec('SELECT * FROM alliance_wars WHERE id = ? LIMIT 1', [$warId]);
        if (empty($war)) {
            return;
        }

        $war = $war[0];
        $challengerScore = (int) $war['challenger_score'];
        $defenderScore   = (int) $war['defender_score'];
        $winnerId = null;

        if ($challengerScore > $defenderScore) {
            $winnerId = (int) $war['challenger_id'];
        } elseif ($defenderScore > $challengerScore) {
            $winnerId = (int) $war['defender_id'];
        }

        $this->db->exec(
            'UPDATE alliance_wars SET status = ?, winner_id = ? WHERE id = ?',
            [self::STATUS_ENDED, $winnerId, $warId]
        );

        // Notify all members of both alliances
        try {
            $notif = new NotificationService($this->db);
            $allMembers = $this->db->exec(
                'SELECT id FROM users WHERE alliance = ? OR alliance = ? LIMIT 200',
                [(int) $war['challenger_id'], (int) $war['defender_id']]
            );
            foreach ($allMembers as $member) {
                $notif->notify((int) $member['id'], NotificationService::TYPE_WAR_DECLARED, 'notifWarEnded', '/alliance/' . (int) $war['challenger_id']);
            }
        } catch (\Throwable $e) {
            // non-critical
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAllWars(): array
    {
        return $this->db->exec(
            'SELECT aw.*,
                    a1.name AS challenger_name,
                    a2.name AS defender_name,
                    aw_w.name AS winner_name
             FROM alliance_wars aw
             LEFT JOIN alliances a1 ON a1.id = aw.challenger_id
             LEFT JOIN alliances a2 ON a2.id = aw.defender_id
             LEFT JOIN alliances aw_w ON aw_w.id = aw.winner_id
             ORDER BY aw.id DESC
             LIMIT 100'
        ) ?: [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getWarScores(int $warId): array
    {
        return $this->db->exec(
            'SELECT aws.*, u.name AS username
             FROM alliance_war_scores aws
             LEFT JOIN users u ON u.id = aws.user_id
             WHERE aws.war_id = ?
             ORDER BY aws.score DESC',
            [$warId]
        ) ?: [];
    }

    public function checkDueWars(): void
    {
        $dueWars = $this->db->exec(
            'SELECT id FROM alliance_wars WHERE status = ? AND end_time <= NOW()',
            [self::STATUS_ACTIVE]
        ) ?: [];

        foreach ($dueWars as $row) {
            try {
                $this->processWarEnd((int) $row['id']);
            } catch (\Throwable $e) {
                // non-critical
            }
        }
    }
}

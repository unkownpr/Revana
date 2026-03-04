<?php

declare(strict_types=1);

namespace Devana\Services;

final class AllianceService
{
    private \DB\SQL $db;

    public function __construct(\DB\SQL $db)
    {
        $this->db = $db;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $result = $this->db->exec('SELECT * FROM alliances WHERE id = ?', [$id]);

        return $result[0] ?? null;
    }

    public function create(string $name, int $founderId): int
    {
        $this->db->exec(
            'INSERT INTO alliances (name, founder) VALUES (?, ?)',
            [$name, $founderId]
        );

        $allianceId = (int)$this->db->lastInsertId();

        $this->db->exec(
            "UPDATE users SET alliance = ?, `rank` = 'founder' WHERE id = ?",
            [$allianceId, $founderId]
        );

        return $allianceId;
    }

    public function isNameTaken(string $name): bool
    {
        $result = $this->db->exec(
            'SELECT COUNT(*) AS cnt FROM alliances WHERE name = ?',
            [$name]
        );

        return (int)($result[0]['cnt'] ?? 0) > 0;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getMembers(int $allianceId): array
    {
        return $this->db->exec(
            'SELECT id, name, points, `rank` FROM users WHERE alliance = ? ORDER BY points DESC',
            [$allianceId]
        );
    }

    public function join(int $userId, int $allianceId): void
    {
        $this->db->exec(
            "UPDATE users SET alliance = ?, `rank` = 'member' WHERE id = ?",
            [$allianceId, $userId]
        );
    }

    public function kick(int $userId): void
    {
        $this->db->exec(
            "UPDATE users SET alliance = 0, `rank` = '' WHERE id = ?",
            [$userId]
        );
    }

    public function quit(int $userId): void
    {
        $this->kick($userId);
    }

    public function updateDescription(int $allianceId, string $description): void
    {
        $this->db->exec(
            'UPDATE alliances SET description = ? WHERE id = ?',
            [$description, $allianceId]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getPacts(int $allianceId): array
    {
        return $this->db->exec(
            'SELECT * FROM pacts WHERE a1 = ? OR a2 = ?',
            [$allianceId, $allianceId]
        );
    }

    public function addPact(int $type, int $a1, int $a2): void
    {
        $this->db->exec(
            'INSERT IGNORE INTO pacts (type, a1, a2) VALUES (?, ?, ?)',
            [$type, $a1, $a2]
        );
    }

    public function removePact(int $type, int $a1, int $a2): void
    {
        $this->db->exec(
            'DELETE FROM pacts WHERE type = ? AND ((a1 = ? AND a2 = ?) OR (a1 = ? AND a2 = ?))',
            [$type, $a1, $a2, $a2, $a1]
        );
    }
}

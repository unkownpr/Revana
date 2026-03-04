<?php

declare(strict_types=1);

namespace Devana\Services;

final class StatsService
{
    private \DB\SQL $db;

    public function __construct(\DB\SQL $db)
    {
        $this->db = $db;
    }

    /**
     * @return array{players: int, towns: int, alliances: int}
     */
    public function getFooterStats(): array
    {
        $row = $this->db->exec(
            'SELECT
                (SELECT COUNT(*) FROM users WHERE level > 0) AS players,
                (SELECT COUNT(*) FROM towns WHERE owner > 0) AS towns,
                (SELECT COUNT(*) FROM alliances) AS alliances'
        );
        return [
            'players' => (int) ($row[0]['players'] ?? 0),
            'towns' => (int) ($row[0]['towns'] ?? 0),
            'alliances' => (int) ($row[0]['alliances'] ?? 0),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getTopPlayers(int $limit = 25, int $offset = 0, string $search = ''): array
    {
        $search = trim($search);
        $params = [];
        $where = 'WHERE u.level > 0';
        if ($search !== '') {
            $where .= ' AND u.name LIKE ?';
            $params[] = '%' . $search . '%';
        }
        $params[] = $limit;
        $params[] = $offset;

        return $this->db->exec(
            'SELECT u.id, u.name, u.points, u.faction, u.alliance,
                    u.avatar_seed, u.avatar_style, u.avatar_options,
                    f.name AS faction_name, a.id AS alliance_id, a.name AS alliance_tag
             FROM users u
             LEFT JOIN factions f ON u.faction = f.id
             LEFT JOIN alliances a ON u.alliance = a.id
             ' . $where . '
             ORDER BY u.points DESC LIMIT ? OFFSET ?',
            $params
        );
    }

    public function countPlayers(string $search = ''): int
    {
        $search = trim($search);
        if ($search !== '') {
            $row = $this->db->exec(
                'SELECT COUNT(*) AS cnt FROM users WHERE level > 0 AND name LIKE ?',
                ['%' . $search . '%']
            );
            return (int) ($row[0]['cnt'] ?? 0);
        }

        $row = $this->db->exec('SELECT COUNT(*) AS cnt FROM users WHERE level > 0');
        return (int) ($row[0]['cnt'] ?? 0);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getTopAlliances(int $limit = 25, int $offset = 0): array
    {
        return $this->db->exec(
            'SELECT a.id, a.name, a.name AS tag,
                    COUNT(u.id) AS member_count,
                    COALESCE(SUM(u.points), 0) AS total_points,
                    COALESCE(ROUND(AVG(u.points)), 0) AS avg_points
             FROM alliances a
             LEFT JOIN users u ON u.alliance = a.id AND u.level > 0
             GROUP BY a.id
             ORDER BY total_points DESC LIMIT ? OFFSET ?',
            [$limit, $offset]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getTopTowns(int $limit = 25, int $offset = 0): array
    {
        return $this->db->exec(
            'SELECT t.id, t.name, t.population,
                    u.name AS owner_name, u.id AS owner_id,
                    u.avatar_seed, u.avatar_style, u.avatar_options,
                    COALESCE(m.x, 0) AS x, COALESCE(m.y, 0) AS y
             FROM towns t
             LEFT JOIN users u ON t.owner = u.id
             LEFT JOIN map m ON m.type = 3 AND m.subtype = t.id
             WHERE t.owner > 0
             ORDER BY t.population DESC LIMIT ? OFFSET ?',
            [$limit, $offset]
        );
    }
}

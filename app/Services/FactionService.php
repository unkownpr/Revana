<?php declare(strict_types=1);

namespace Devana\Services;

final class FactionService
{
    private \DB\SQL $db;

    public function __construct(\DB\SQL $db)
    {
        $this->db = $db;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAllWithStats(): array
    {
        return $this->db->exec(
            'SELECT f.id, f.name, f.grPath, f.ratio,
                    (SELECT COUNT(*) FROM users u WHERE u.faction = f.id AND u.level > 0) AS users_count,
                    (SELECT COUNT(*) FROM buildings b WHERE b.faction = f.id) AS buildings_count,
                    (SELECT COUNT(*) FROM units un WHERE un.faction = f.id) AS units_count,
                    (SELECT COUNT(*) FROM weapons w WHERE w.faction = f.id) AS weapons_count
             FROM factions f
             ORDER BY f.id ASC'
        );
    }

    /**
     * Create a new faction and clone gameplay data from a template faction.
     * Returns new faction id, or 0 on failure.
     */
    public function createFromTemplate(string $name, int $templateFactionId, string $grPath, float $ratio): int
    {
        $name = trim($name);
        if ($name === '' || $templateFactionId <= 0) {
            return 0;
        }

        if (!preg_match('#^[a-zA-Z0-9/_-]+/$#', $grPath)) {
            return 0;
        }

        if ($ratio <= 0) {
            return 0;
        }

        $template = $this->db->exec('SELECT id FROM factions WHERE id = ? LIMIT 1', [$templateFactionId]);
        if (empty($template)) {
            return 0;
        }

        try {
            $this->db->begin();

            $this->db->exec(
                'INSERT INTO factions (name, grPath, ratio) VALUES (?, ?, ?)',
                [$name, $grPath, $ratio]
            );
            $newIdRow = $this->db->exec('SELECT LAST_INSERT_ID() AS id');
            $newFactionId = (int) ($newIdRow[0]['id'] ?? 0);
            if ($newFactionId <= 0) {
                $this->db->rollback();
                return 0;
            }

            $this->db->exec(
                'INSERT INTO buildings (type, faction, name, requirements, input, output, duration, upkeep, description)
                 SELECT type, ?, name, requirements, input, output, duration, upkeep, description
                 FROM buildings WHERE faction = ?',
                [$newFactionId, $templateFactionId]
            );

            $this->db->exec(
                'INSERT INTO units (type, faction, name, requirements, input, hp, attack, defense, speed, duration, description)
                 SELECT type, ?, name, requirements, input, hp, attack, defense, speed, duration, description
                 FROM units WHERE faction = ?',
                [$newFactionId, $templateFactionId]
            );

            $this->db->exec(
                'INSERT INTO weapons (type, faction, name, input, duration, description)
                 SELECT type, ?, name, input, duration, description
                 FROM weapons WHERE faction = ?',
                [$newFactionId, $templateFactionId]
            );

            $this->db->commit();
            return $newFactionId;
        } catch (\Throwable $e) {
            try {
                $this->db->rollback();
            } catch (\Throwable $e2) {
            }
            return 0;
        }
    }

    public function updateMeta(int $factionId, string $name, string $grPath, float $ratio): bool
    {
        if ($factionId <= 0) {
            return false;
        }

        $name = trim($name);
        if ($name === '') {
            return false;
        }

        if (!preg_match('#^[a-zA-Z0-9/_-]+/$#', $grPath)) {
            return false;
        }

        if ($ratio <= 0) {
            return false;
        }

        $this->db->exec(
            'UPDATE factions SET name = ?, grPath = ?, ratio = ? WHERE id = ?',
            [$name, $grPath, $ratio, $factionId]
        );

        return true;
    }

    public function countAll(): int
    {
        $row = $this->db->exec('SELECT COUNT(*) AS cnt FROM factions');
        return (int) ($row[0]['cnt'] ?? 0);
    }

    public function countUsersByFaction(int $factionId): int
    {
        $row = $this->db->exec('SELECT COUNT(*) AS cnt FROM users WHERE faction = ? AND level > 0', [$factionId]);
        return (int) ($row[0]['cnt'] ?? 0);
    }

    public function deleteFaction(int $factionId): bool
    {
        if ($factionId <= 0) {
            return false;
        }

        try {
            $this->db->begin();
            $this->db->exec('DELETE FROM buildings WHERE faction = ?', [$factionId]);
            $this->db->exec('DELETE FROM units WHERE faction = ?', [$factionId]);
            $this->db->exec('DELETE FROM weapons WHERE faction = ?', [$factionId]);
            $this->db->exec('DELETE FROM factions WHERE id = ?', [$factionId]);
            $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            try {
                $this->db->rollback();
            } catch (\Throwable $e2) {
            }
            return false;
        }
    }
}

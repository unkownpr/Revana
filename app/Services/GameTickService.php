<?php declare(strict_types=1);

namespace Devana\Services;

use Devana\Services\AllianceWarService;
use Devana\Services\SeasonService;

final class GameTickService
{
    private \DB\SQL $db;

    public function __construct(\DB\SQL $db)
    {
        $this->db = $db;
    }

    public function tickDueQueues(int $maxTowns = 60): void
    {
        $townIds = $this->collectDueTownIds($maxTowns);
        if (empty($townIds)) {
            return;
        }

        $queueService = new QueueService($this->db);

        foreach ($townIds as $townId) {
            $townRow = $this->db->exec(
                'SELECT t.id, t.owner, COALESCE(u.faction, 1) AS faction
                 FROM towns t
                 LEFT JOIN users u ON u.id = t.owner
                 WHERE t.id = ?
                 LIMIT 1',
                [$townId]
            );
            if (empty($townRow)) {
                continue;
            }

            $factionId = (int) ($townRow[0]['faction'] ?? 1);
            if ($factionId < 1) {
                $factionId = 1;
            }

            $queueService->processConstructionQueue($townId, $factionId);
            $queueService->processWeaponQueue($townId);
            $queueService->processUnitQueue($townId);
            $queueService->processUpgradeQueue($townId);
            $queueService->processTradeQueue($townId);
            $queueService->processArmyQueue($townId);
        }

        // Respawn any barbarian camps whose timer has elapsed.
        try {
            (new BarbarianService($this->db))->respawnDueCamps();
        } catch (\Throwable $e) {
            // Non-critical — don't block normal tick.
        }

        // Check season end
        try {
            $season = (new SeasonService($this->db))->getActiveSeason();
            if ($season !== null && strtotime($season['end_time']) <= time() && !(int) $season['reset_done']) {
                (new SeasonService($this->db))->processSeasonEnd((int) $season['id']);
            }
        } catch (\Throwable $e) {
            // Non-critical
        }

        // Check alliance war end
        try {
            (new AllianceWarService($this->db))->checkDueWars();
        } catch (\Throwable $e) {
            // Non-critical
        }
    }

    public function tickResources(int $batchSize = 250, int $maxBatches = 8): int
    {
        $batchSize = max(25, $batchSize);
        $maxBatches = max(1, $maxBatches);
        $processed = 0;
        $resourceService = new ResourceService($this->db);

        for ($i = 0; $i < $maxBatches; $i++) {
            $rows = $this->db->exec(
                'SELECT id
                 FROM towns
                 WHERE owner > 0
                   AND lastCheck < DATE_SUB(NOW(), INTERVAL 30 SECOND)
                 ORDER BY lastCheck ASC
                 LIMIT ?',
                [$batchSize]
            );

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $townId = (int) ($row['id'] ?? 0);
                if ($townId <= 0) {
                    continue;
                }
                $resourceService->updateResources($townId);
                $processed++;
            }

            if (count($rows) < $batchSize) {
                break;
            }
        }

        return $processed;
    }

    /**
     * @return array<int, int>
     */
    private function collectDueTownIds(int $maxTowns): array
    {
        $maxTowns = max(10, $maxTowns);
        $ids = [];

        $collect = function (string $sql) use (&$ids): void {
            $rows = $this->db->exec($sql);
            foreach ($rows as $row) {
                $id = (int) ($row['town_id'] ?? 0);
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }
        };

        $collect('SELECT town AS town_id FROM c_queue WHERE dueTime <= NOW() ORDER BY dueTime ASC LIMIT 50');
        $collect('SELECT town AS town_id FROM u_queue WHERE dueTime <= NOW() ORDER BY dueTime ASC LIMIT 50');
        $collect('SELECT town AS town_id FROM w_queue WHERE dueTime <= NOW() ORDER BY dueTime ASC LIMIT 50');
        $collect('SELECT town AS town_id FROM uup_queue WHERE dueTime <= NOW() ORDER BY dueTime ASC LIMIT 50');
        $collect('SELECT seller AS town_id FROM t_queue WHERE type IN (1, 2) AND dueTime <= NOW() ORDER BY dueTime ASC LIMIT 50');
        $collect('SELECT buyer AS town_id FROM t_queue WHERE type IN (1, 2) AND dueTime <= NOW() ORDER BY dueTime ASC LIMIT 50');
        $collect('SELECT town AS town_id FROM a_queue WHERE dueTime <= NOW() ORDER BY dueTime ASC LIMIT 50');
        $collect('SELECT target AS town_id FROM a_queue WHERE dueTime <= NOW() ORDER BY dueTime ASC LIMIT 50');

        return array_slice(array_values($ids), 0, $maxTowns);
    }
}

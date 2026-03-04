<?php declare(strict_types=1);

namespace Devana\Controllers;

use Devana\Helpers\DataParser;
use Devana\Services\BuildingService;
use Devana\Services\QueueService;

final class QueueController extends Controller
{
    /**
     * Cancel construction and refund resources.
     * POST /town/@id/cancel/construction
     */
    public function cancelConstruction(\Base $f3, array $params): void
    {
        $townId = (int) ($params['id'] ?? 0);
        if (!$this->requireCsrf('/town/' . $townId)) return;
        $user = $this->requireAuth();
        if ($user === null) return;

        if (!$this->requireTownOwnership($townId)) return;

        $b = (int) $this->post('b', 0);
        $subB = (int) $this->post('subB', -1);

        $this->withTableLock(['c_queue', 'towns', 'buildings'], function () use ($townId, $b, $subB, $user): void {
            // Check if actually in queue
            $queue = $this->db->exec(
                'SELECT 1 FROM c_queue WHERE town = ? AND b = ? AND subB = ?',
                [$townId, $b, $subB]
            );
            if (empty($queue)) {
                $this->flashAndRedirect('Not in queue.', '/town/' . $townId);
                return;
            }

            $buildingService = new BuildingService($this->db);
            $building = $buildingService->getByTypeAndFaction($b, $user['faction']);
            if ($building === null) {
                $this->flashAndRedirect('Building not found.', '/town/' . $townId);
                return;
            }

            $townService = $this->townService();
            $town = $townService->findById($townId);
            $costParts = DataParser::toFloatArray($building['input']);
            $res = DataParser::toFloatArray($town['resources']);

            // Refund exactly what was reserved at queue time (base input cost).
            for ($i = 0; $i < count($costParts); $i++) {
                $res[$i] += $costParts[$i];
            }

            $newRes = DataParser::serializeRounded($res);

            // Delete from queue and update resources
            $this->db->exec(
                'DELETE FROM c_queue WHERE town = ? AND b = ? AND subB = ?',
                [$townId, $b, $subB]
            );
            $this->db->exec(
                'UPDATE towns SET resources = ? WHERE id = ?',
                [$newRes, $townId]
            );
        });

        $this->redirect('/town/' . $townId);
    }

    /**
     * Cancel unit training and refund resources + weapons.
     * POST /town/@id/cancel/unit
     */
    public function cancelUnit(\Base $f3, array $params): void
    {
        $townId = (int) ($params['id'] ?? 0);
        if (!$this->requireCsrf('/town/' . $townId)) return;
        $user = $this->requireAuth();
        if ($user === null) return;

        if (!$this->requireTownOwnership($townId)) return;

        $unitType = (int) $this->post('type', 0);

        $this->withTableLock(['u_queue', 'towns', 'units'], function () use ($townId, $unitType, $user): void {
            // Get queue item
            $queueItem = $this->db->exec(
                'SELECT quantity FROM u_queue WHERE town = ? AND type = ?',
                [$townId, $unitType]
            );
            if (empty($queueItem)) {
                $this->flashAndRedirect('Not in queue.', '/town/' . $townId);
                return;
            }
            $quantity = (int) $queueItem[0]['quantity'];

            // Get unit data
            $unit = $this->db->exec(
                'SELECT input, requirements FROM units WHERE type = ? AND faction = ?',
                [$unitType, $user['faction']]
            );
            if (empty($unit)) {
                $this->flashAndRedirect('Unit not found.', '/town/' . $townId);
                return;
            }
            $unit = $unit[0];

            $townService = $this->townService();
            $town = $townService->findById($townId);
            $costParts = DataParser::toFloatArray($unit['input']);
            $res = DataParser::toFloatArray($town['resources']);

            // Refund resources: cost * quantity
            for ($i = 0; $i < count($costParts); $i++) {
                $res[$i] += $costParts[$i] * $quantity;
            }

            // Refund weapons if unit has weapon requirements
            $weapons = DataParser::toIntArray($town['weapons']);
            if (!empty($unit['requirements'])) {
                $reqs = explode('-', $unit['requirements']);
                foreach ($reqs as $weaponType) {
                    $weapons[(int) $weaponType] += $quantity;
                }
            }

            $newRes = DataParser::serializeRounded($res);

            $this->db->exec('DELETE FROM u_queue WHERE town = ? AND type = ?', [$townId, $unitType]);
            $this->db->exec(
                'UPDATE towns SET resources = ?, weapons = ? WHERE id = ?',
                [$newRes, DataParser::serialize($weapons), $townId]
            );
        });

        $this->redirect('/town/' . $townId);
    }

    /**
     * Cancel weapon forging and refund resources.
     * POST /town/@id/cancel/weapon
     */
    public function cancelWeapon(\Base $f3, array $params): void
    {
        $townId = (int) ($params['id'] ?? 0);
        if (!$this->requireCsrf('/town/' . $townId)) return;
        $user = $this->requireAuth();
        if ($user === null) return;

        if (!$this->requireTownOwnership($townId)) return;

        $weaponType = (int) $this->post('type', 0);

        $this->withTableLock(['w_queue', 'towns', 'weapons'], function () use ($townId, $weaponType, $user): void {
            $queueItem = $this->db->exec(
                'SELECT quantity FROM w_queue WHERE town = ? AND type = ?',
                [$townId, $weaponType]
            );
            if (empty($queueItem)) {
                $this->flashAndRedirect('Not in queue.', '/town/' . $townId);
                return;
            }
            $quantity = (int) $queueItem[0]['quantity'];

            $weapon = $this->db->exec(
                'SELECT input FROM weapons WHERE type = ? AND faction = ?',
                [$weaponType, $user['faction']]
            );
            if (empty($weapon)) {
                $this->flashAndRedirect('Weapon not found.', '/town/' . $townId);
                return;
            }

            $townService = $this->townService();
            $town = $townService->findById($townId);
            $costParts = DataParser::toFloatArray($weapon[0]['input']);
            $res = DataParser::toFloatArray($town['resources']);

            for ($i = 0; $i < count($costParts); $i++) {
                $res[$i] += $costParts[$i] * $quantity;
            }

            $newRes = DataParser::serializeRounded($res);

            $this->db->exec('DELETE FROM w_queue WHERE town = ? AND type = ?', [$townId, $weaponType]);
            $this->db->exec('UPDATE towns SET resources = ? WHERE id = ?', [$newRes, $townId]);
        });

        $this->redirect('/town/' . $townId);
    }

    /**
     * Cancel army movement - return troops to garrison.
     * POST /town/@id/cancel/army
     */
    public function cancelArmy(\Base $f3, array $params): void
    {
        $townId = (int) ($params['id'] ?? 0);
        if (!$this->requireCsrf('/town/' . $townId)) return;

        if (!$this->requireTownOwnership($townId)) return;

        $armyId = (int) $this->post('aid', 0);

        $this->withTableLock(['a_queue', 'towns'], function () use ($townId, $armyId): void {
            // Get the army movement
            $movement = $this->db->exec(
                'SELECT army, general FROM a_queue WHERE town = ? AND id = ?',
                [$townId, $armyId]
            );
            if (empty($movement)) {
                $this->flashAndRedirect('Movement not found.', '/town/' . $townId);
                return;
            }

            $townService = $this->townService();
            $town = $townService->findById($townId);
            $townArmy = DataParser::toIntArray($town['army']);
            $sentArmy = DataParser::toIntArray($movement[0]['army']);
            $sentGen = explode('-', $movement[0]['general']);
            $townGen = explode('-', $town['general']);

            // Return troops
            for ($i = 0; $i < count($townArmy); $i++) {
                $townArmy[$i] += ($sentArmy[$i] ?? 0);
            }

            // Return general if sent
            if ((int) ($sentGen[0] ?? 0) > 0) {
                $townGen[0] = $sentGen[0];
                $townGen[1] = $sentGen[1] ?? $townGen[1];
                $townGen[2] = $sentGen[2] ?? $townGen[2];
            }

            $this->db->exec(
                'DELETE FROM a_queue WHERE town = ? AND id = ?',
                [$townId, $armyId]
            );
            $this->db->exec(
                'UPDATE towns SET army = ?, general = ?, upkeep = ? WHERE id = ?',
                [DataParser::serialize($townArmy), DataParser::serialize($townGen), array_sum($townArmy), $townId]
            );
        });

        $this->redirect('/town/' . $townId);
    }

    /**
     * Cancel trade offer - return goods to seller.
     * POST /town/@id/cancel/trade
     */
    public function cancelTrade(\Base $f3, array $params): void
    {
        $townId = (int) ($params['id'] ?? 0);
        if (!$this->requireCsrf('/town/' . $townId)) return;

        if (!$this->requireTownOwnership($townId)) return;

        $sType = (int) $this->post('sType', 0);
        $sSubType = (int) $this->post('sSubType', 0);
        $bType = (int) $this->post('bType', 0);
        $bSubType = (int) $this->post('bSubType', 0);

        $this->withTableLock(['t_queue', 'towns'], function () use ($townId, $sType, $sSubType, $bType, $bSubType): void {
            // Get the trade offer
            $offer = $this->db->exec(
                'SELECT sQ FROM t_queue WHERE type = 0 AND seller = ? AND sType = ? AND sSubType = ? AND bType = ? AND bSubType = ?',
                [$townId, $sType, $sSubType, $bType, $bSubType]
            );
            if (empty($offer)) {
                $this->flashAndRedirect('Offer not found.', '/town/' . $townId . '/market');
                return;
            }

            $townService = $this->townService();
            $town = $townService->findById($townId);
            $quantity = (int) $offer[0]['sQ'];

            // Return goods: sType=0 means resource, sType=1 means weapon
            if ($sType === 0) {
                $res = DataParser::toFloatArray($town['resources']);
                $res[$sSubType] += $quantity;
                $this->db->exec(
                    'UPDATE towns SET resources = ? WHERE id = ?',
                    [DataParser::serializeRounded($res), $townId]
                );
            } else {
                $weapons = DataParser::toIntArray($town['weapons']);
                $weapons[$sSubType] += $quantity;
                $this->db->exec(
                    'UPDATE towns SET weapons = ? WHERE id = ?',
                    [DataParser::serialize($weapons), $townId]
                );
            }

            $this->db->exec(
                'DELETE FROM t_queue WHERE seller = ? AND sType = ? AND sSubType = ? AND bType = ? AND bSubType = ? AND type = 0',
                [$townId, $sType, $sSubType, $bType, $bSubType]
            );
        });

        $this->redirect('/town/' . $townId . '/market');
    }

    /**
     * Cancel unit upgrade and refund resources.
     * POST /town/@id/cancel/upgrade
     */
    public function cancelUpgrade(\Base $f3, array $params): void
    {
        $townId = (int) ($params['id'] ?? 0);
        if (!$this->requireCsrf('/town/' . $townId)) return;
        $user = $this->requireAuth();
        if ($user === null) return;

        if (!$this->requireTownOwnership($townId)) return;

        $unitType = (int) $this->post('unit', 0);
        $tree = (int) $this->post('tree', 17);

        // Validate tree
        if (!in_array($tree, [17, 18, 19], true)) {
            $this->flashAndRedirect('Invalid upgrade tree.', '/town/' . $townId);
            return;
        }

        $this->withTableLock(['uup_queue', 'towns', 'units'], function () use ($townId, $unitType, $tree, $user): void {
            // Check if in queue
            $queueItem = $this->db->exec(
                'SELECT 1 FROM uup_queue WHERE town = ? AND unit = ? AND tree = ?',
                [$townId, $unitType, $tree]
            );
            if (empty($queueItem)) {
                $this->flashAndRedirect('Not in queue.', '/town/' . $townId);
                return;
            }

            // Get unit cost
            $unit = $this->db->exec(
                'SELECT input FROM units WHERE type = ? AND faction = ?',
                [$unitType, $user['faction']]
            );
            if (empty($unit)) {
                $this->flashAndRedirect('Unit not found.', '/town/' . $townId);
                return;
            }

            $townService = $this->townService();
            $town = $townService->findById($townId);
            $costParts = DataParser::toFloatArray($unit[0]['input']);
            $res = DataParser::toFloatArray($town['resources']);

            // Get current upgrade level for cost calculation
            $col = QueueService::UPGRADE_TREE_MAP[$tree];
            $upgrades = DataParser::toIntArray($town[$col]);
            $currentLevel = $upgrades[$unitType] ?? 0;

            // Refund: cost * (currentLevel + 1) - legacy formula
            for ($i = 0; $i < count($costParts); $i++) {
                $res[$i] += $costParts[$i] * ($currentLevel + 1);
            }

            $newRes = DataParser::serializeRounded($res);

            $this->db->exec(
                'DELETE FROM uup_queue WHERE town = ? AND unit = ? AND tree = ?',
                [$townId, $unitType, $tree]
            );
            $this->db->exec('UPDATE towns SET resources = ? WHERE id = ?', [$newRes, $townId]);
        });

        $this->redirect('/town/' . $townId);
    }

    /**
     * @param array<int, string> $tables
     */
    private function withTableLock(array $tables, callable $callback): void
    {
        $lockParts = [];
        foreach ($tables as $table) {
            $lockParts[] = $table . ' WRITE';
        }

        $this->db->exec('LOCK TABLES ' . implode(', ', $lockParts));
        try {
            $callback();
        } finally {
            $this->db->exec('UNLOCK TABLES');
        }
    }
}

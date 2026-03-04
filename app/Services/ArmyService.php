<?php

declare(strict_types=1);

namespace Devana\Services;

use Devana\Helpers\DataParser;

final class ArmyService
{
    private \DB\SQL $db;

    public function __construct(\DB\SQL $db)
    {
        $this->db = $db;
    }

    /**
     * Get units for a faction.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getUnitsByFaction(int $factionId): array
    {
        return $this->db->exec(
            'SELECT * FROM units WHERE faction = ? ORDER BY type',
            [$factionId]
        );
    }

    /**
     * Add units to training queue.
     */
    public function queueTraining(
        int $townId,
        int $unitType,
        int $quantity,
        string $duration
    ): void {
        $totalSeconds = DataParser::durationToSeconds($duration) * $quantity;

        $this->db->exec(
            'INSERT INTO u_queue (town, type, quantity, dueTime) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND)) ON DUPLICATE KEY UPDATE quantity = quantity + ?, dueTime = DATE_ADD(dueTime, INTERVAL ? SECOND)',
            [$townId, $unitType, $quantity, $totalSeconds, $quantity, $totalSeconds]
        );
    }

    /**
     * Add weapons to forging queue.
     */
    public function queueWeapon(
        int $townId,
        int $weaponType,
        int $quantity,
        string $duration
    ): void {
        $totalSeconds = DataParser::durationToSeconds($duration) * $quantity;

        $this->db->exec(
            'INSERT INTO w_queue (town, type, quantity, dueTime) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND)) ON DUPLICATE KEY UPDATE quantity = quantity + ?, dueTime = DATE_ADD(dueTime, INTERVAL ? SECOND)',
            [$townId, $weaponType, $quantity, $totalSeconds, $quantity, $totalSeconds]
        );
    }

    /**
     * Dispatch army from town to target.
     *
     * @param array<int, int> $army
     * @param array<int, int> $general
     * @param array<int, int> $resources Resources to send (crop, lumber, stone, iron, gold)
     */
    public function dispatch(
        int $fromTownId,
        int $toTownId,
        int $actionType,
        array $army,
        array $general,
        int $travelSeconds,
        array $resources = []
    ): void {
        $result = $this->db->exec(
            'SELECT COALESCE(MAX(id), 0) + 1 AS nextId FROM a_queue WHERE town = ?',
            [$fromTownId]
        );
        $nextId = (int)$result[0]['nextId'];

        $armyStr = DataParser::serialize($army);
        $generalStr = DataParser::serialize($general);
        $emptyWeaps = '0-0-0-0-0-0-0-0-0-0-0';

        // Resources to carry (sent as rLoot for reinforce/resource transfer)
        $resStr = !empty($resources) ? DataParser::serialize($resources) : '0-0-0-0-0';

        $hours = (int)floor($travelSeconds / 3600);
        $minutes = (int)floor(($travelSeconds % 3600) / 60);
        $sentStr = $hours . '-' . $minutes;

        $this->db->exec(
            'INSERT INTO a_queue (town, target, id, type, phase, dueTime, army, general, uup, wup, aup, rLoot, wLoot, intel, sent) VALUES (?, ?, ?, ?, 0, DATE_ADD(NOW(), INTERVAL ? SECOND), ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $fromTownId, $toTownId, $nextId, $actionType, $travelSeconds,
                $armyStr, $generalStr, '0-0-0-0-0', $emptyWeaps, '0-0-0-0-0',
                $resStr, $emptyWeaps, '', $sentStr,
            ]
        );

        $this->removeDispatchedUnits($fromTownId, $army);
    }

    /**
     * Remove dispatched units from town garrison.
     *
     * @param array<int, int> $army
     */
    private function removeDispatchedUnits(int $townId, array $army): void
    {
        $town = $this->db->exec('SELECT army FROM towns WHERE id = ?', [$townId]);

        if (empty($town)) {
            return;
        }

        $townArmy = DataParser::toIntArray($town[0]['army']);

        for ($i = 0; $i < count($army); $i++) {
            $townArmy[$i] = max(0, ($townArmy[$i] ?? 0) - ($army[$i] ?? 0));
        }

        $this->db->exec(
            'UPDATE towns SET army = ?, upkeep = ? WHERE id = ?',
            [DataParser::serialize($townArmy), array_sum($townArmy), $townId]
        );
    }
}

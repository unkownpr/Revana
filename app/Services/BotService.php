<?php declare(strict_types=1);

namespace Devana\Services;

use Devana\Helpers\DataParser;

/**
 * Bot AI service.
 *
 * Bots are registered as normal users (level=1, is_bot=1).
 * They are indistinguishable from real players via the public UI.
 *
 * Profiles:
 *   builder  – prioritises building upgrades, peaceful
 *   warrior  – trains units and attacks barbarian camps
 *   raider   – sends raids against player towns
 *   trader   – posts market offers when resources overflow
 *   diplomat – joins alliances and sends friendly messages
 *   balanced – rotates across all behaviours
 */
final class BotService
{
    public const PROFILES = ['builder', 'warrior', 'raider', 'trader', 'diplomat', 'balanced'];

    /** Starting resources granted to a new bot town. */
    private const STARTING_RESOURCES = '2000-2000-2000-2000-1000';

    /** Starting production set directly so bots always generate resources. */
    private const STARTING_PRODUCTION = '80-80-80-80-40';

    /** Storage limits for the bot town (same as a normal starting player). */
    private const STARTING_LIMITS = '800-600-500-0-0-0-0-0-0-0-0-0-0';

    /** Unit training batch size. */
    private const TRAIN_BATCH = 5;

    /**
     * Minimum army size before a warrior/raider acts.
     * Kept high so bots always maintain a defensive garrison at home.
     */
    private const MIN_ARMY_TO_ATTACK = 10;

    /**
     * Fraction of the army dispatched per attack.
     * Low on purpose so bots are never left defenceless.
     */
    private const ATTACK_RATIO = 0.30;

    private \DB\SQL $db;

    public function __construct(\DB\SQL $db)
    {
        $this->db = $db;
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Main cron entry point.
     * Spawns missing bots and ticks each active bot.
     *
     * @return int Number of bots ticked.
     */
    public function tick(): int
    {
        if (!$this->isEnabled()) {
            return 0;
        }

        $this->spawnMissingBots();

        $bots = $this->db->exec(
            'SELECT u.id, u.faction, u.alliance, u.bot_profile
             FROM users u
             WHERE u.is_bot = 1 AND u.level = 1
             ORDER BY u.id ASC'
        ) ?: [];

        $count = 0;
        foreach ($bots as $bot) {
            try {
                $this->tickBot($bot);
                $count++;
            } catch (\Throwable) {
                // One bot's failure must not stop the others.
            }
        }

        return $count;
    }

    /**
     * Create bots until the configured target count is reached.
     *
     * @return int Number of bots spawned.
     */
    public function spawnMissingBots(): int
    {
        $target = $this->targetBotCount();
        if ($target <= 0) {
            return 0;
        }

        $current = (int) ($this->db->exec(
            'SELECT COUNT(*) AS cnt FROM users WHERE is_bot = 1 AND level = 1'
        )[0]['cnt'] ?? 0);

        $needed  = max(0, $target - $current);
        $spawned = 0;

        $nameService = new BotNameService($this->db);
        $factions    = [1, 2, 3];

        for ($i = 0; $i < $needed; $i++) {
            $profile   = self::PROFILES[array_rand(self::PROFILES)];
            $factionId = $factions[array_rand($factions)];
            $name      = $nameService->generateUniqueName($factionId);

            if ($this->createBot($name, $profile, $factionId) > 0) {
                $spawned++;
            }
        }

        return $spawned;
    }

    /**
     * Register a single bot user and place their starting town.
     *
     * @return int New user ID, or 0 on failure.
     */
    public function createBot(string $name, string $profile, int $factionId): int
    {
        if (!in_array($profile, self::PROFILES, true)) {
            $profile = 'balanced';
        }
        $factionId = max(1, min(3, $factionId));

        $fakeEmail = 'bot_' . substr(md5($name . microtime()), 0, 12) . '@game.local';
        $fakePass  = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
        $fakeIp    = '127.0.0.' . rand(1, 254);

        $this->db->exec(
            'INSERT INTO users
                (name, pass, email, level, joined, lastVisit, faction,
                 is_bot, bot_profile, ip, lang, mute, description)
             VALUES (?, ?, ?, 1, CURDATE(), NOW(), ?, 1, ?, ?, ?, 0, ?)',
            [
                $name, $fakePass, $fakeEmail, $factionId,
                $profile, $fakeIp, 'en.php',
                $this->profileDescription($profile),
            ]
        );

        $botId = (int) ($this->db->exec('SELECT LAST_INSERT_ID() AS id')[0]['id'] ?? 0);
        if ($botId <= 0) {
            return 0;
        }

        $this->placeStartingTown($botId, $name, $factionId);

        return $botId;
    }

    /**
     * Permanently remove a bot and free all their resources.
     */
    public function deleteBot(int $botId): void
    {
        // Free map tiles occupied by bot towns.
        $towns = $this->db->exec(
            'SELECT id FROM towns WHERE owner = ?',
            [$botId]
        ) ?: [];

        foreach ($towns as $town) {
            $this->db->exec(
                'UPDATE map SET type = 0, subtype = 0 WHERE type = 3 AND subtype = ?',
                [(int) $town['id']]
            );
        }

        $this->db->exec('DELETE FROM towns WHERE owner = ?', [$botId]);
        $this->db->exec('DELETE FROM messages   WHERE sender = ? OR recipient = ?', [$botId, $botId]);
        $this->db->exec('DELETE FROM reports    WHERE recipient = ?', [$botId]);
        $this->db->exec('DELETE FROM player_missions WHERE user = ?', [$botId]);
        $this->db->exec('UPDATE users SET level = 0 WHERE id = ? AND is_bot = 1', [$botId]);
    }

    /**
     * Return all active bots with summary data for the admin panel.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getBots(): array
    {
        return $this->db->exec(
            'SELECT u.id, u.name, u.bot_profile, u.faction, u.points, u.lastVisit,
                    COUNT(t.id) AS town_count
             FROM users u
             LEFT JOIN towns t ON t.owner = u.id
             WHERE u.is_bot = 1 AND u.level = 1
             GROUP BY u.id
             ORDER BY u.id ASC'
        ) ?: [];
    }

    // -------------------------------------------------------------------------
    // Config helpers
    // -------------------------------------------------------------------------

    public function isEnabled(): bool
    {
        $row = $this->db->exec(
            "SELECT value FROM config WHERE name = 'bot_active' LIMIT 1"
        );
        return !empty($row) && (int) $row[0]['value'] === 1;
    }

    public function targetBotCount(): int
    {
        $row = $this->db->exec(
            "SELECT value FROM config WHERE name = 'bot_count' LIMIT 1"
        );
        return max(0, (int) ($row[0]['value'] ?? 0));
    }

    public function aggressionLevel(): int
    {
        $row = $this->db->exec(
            "SELECT value FROM config WHERE name = 'bot_aggression' LIMIT 1"
        );
        return max(1, min(5, (int) ($row[0]['value'] ?? 2)));
    }

    // -------------------------------------------------------------------------
    // Per-bot tick
    // -------------------------------------------------------------------------

    private function tickBot(array $bot): void
    {
        $botId   = (int) $bot['id'];
        $profile = (string) ($bot['bot_profile'] ?? 'balanced');

        // Keep lastVisit current so the bot appears active.
        $this->db->exec('UPDATE users SET lastVisit = NOW() WHERE id = ?', [$botId]);

        $towns = $this->db->exec(
            'SELECT id, buildings, resources, `limits`, production, army
             FROM towns
             WHERE owner = ?',
            [$botId]
        ) ?: [];

        if (empty($towns)) {
            return;
        }

        $factionId = (int) ($this->db->exec(
            'SELECT faction FROM users WHERE id = ? LIMIT 1',
            [$botId]
        )[0]['faction'] ?? 1);

        foreach ($towns as $town) {
            $townId = (int) $town['id'];
            $this->tryBuildUpgrade($townId, $factionId, $town, $profile);
            $this->tryTrainUnits($townId, $factionId, $town, $profile);
        }

        // One personality action per tick (uses the first/capital town).
        $primaryTown = $towns[0];
        $this->doPersonalityAction($bot, $primaryTown, $profile);
    }

    // -------------------------------------------------------------------------
    // Building
    // -------------------------------------------------------------------------

    private function tryBuildUpgrade(int $townId, int $factionId, array $town, string $profile): void
    {
        // Skip if the queue already has an item for this town.
        $queued = (int) ($this->db->exec(
            'SELECT COUNT(*) AS cnt FROM c_queue WHERE town = ?',
            [$townId]
        )[0]['cnt'] ?? 0);

        if ($queued > 0) {
            return;
        }

        $buildings = $this->db->exec(
            'SELECT type, input, duration FROM buildings
             WHERE faction = ? AND type >= 4
             ORDER BY type ASC',
            [$factionId]
        ) ?: [];

        if (empty($buildings)) {
            return;
        }

        $currentLevels = DataParser::toIntArray((string) $town['buildings']);
        $res           = DataParser::toFloatArray((string) $town['resources']);

        // Profile-based type priority (tries these first, then falls back).
        $priorityTypes = $this->buildingPriorityTypes($profile);

        $candidate = $this->pickAffordableBuilding($buildings, $currentLevels, $res, $priorityTypes)
            ?? $this->pickAffordableBuilding($buildings, $currentLevels, $res, []);

        if ($candidate === null) {
            return;
        }

        $queueService = new QueueService($this->db);
        $queueService->addConstruction($townId, $candidate['type'], -1, $candidate['duration']);
    }

    /**
     * @param  list<array<string, mixed>> $buildings
     * @param  list<int>                  $res
     * @param  list<int>                  $priorityTypes Empty = no filter.
     * @return array{type: int, duration: string}|null
     */
    private function pickAffordableBuilding(
        array $buildings,
        array $currentLevels,
        array $res,
        array $priorityTypes
    ): ?array {
        $buildingService = new BuildingService($this->db);

        foreach ($buildings as $b) {
            $bType = (int) $b['type'];

            if (!empty($priorityTypes) && !in_array($bType, $priorityTypes, true)) {
                continue;
            }

            $currentLevel = $currentLevels[$bType] ?? 0;
            if ($currentLevel >= 10) {
                continue;
            }

            $cost = $buildingService->getCost((string) ($b['input'] ?? ''));
            if (empty($cost)) {
                continue;
            }

            // Check affordability.
            $canAfford = true;
            foreach ($cost as $i => $c) {
                if (($res[$i] ?? 0) < $c) {
                    $canAfford = false;
                    break;
                }
            }

            if (!$canAfford) {
                continue;
            }

            $nextLevel = $currentLevel + 1;
            $duration  = $buildingService->getDuration((string) ($b['duration'] ?? '0:5'), $nextLevel);

            return ['type' => $bType, 'duration' => $duration];
        }

        return null;
    }

    /**
     * @return list<int>
     */
    private function buildingPriorityTypes(string $profile): array
    {
        return match ($profile) {
            'builder'  => [4, 5, 6, 7, 8, 9],    // Storage/warehouse/granary first
            'warrior'  => [10, 11, 12, 13, 14],   // Military buildings first
            'raider'   => [10, 11, 12, 4, 5],
            'trader'   => [4, 5, 6, 7, 16, 17],   // Storage + market
            'diplomat' => [7, 4, 5, 6, 8],         // Town hall + base buildings
            default    => [],                       // No priority, take first affordable
        };
    }

    // -------------------------------------------------------------------------
    // Unit training
    // -------------------------------------------------------------------------

    private function tryTrainUnits(int $townId, int $factionId, array $town, string $profile): void
    {
        // Skip if already training.
        $queued = (int) ($this->db->exec(
            'SELECT COUNT(*) AS cnt FROM u_queue WHERE town = ?',
            [$townId]
        )[0]['cnt'] ?? 0);

        if ($queued > 0) {
            return;
        }

        $army       = DataParser::toIntArray((string) $town['army']);
        $totalUnits = array_sum($army);
        $threshold  = in_array($profile, ['warrior', 'raider'], true) ? 30 : 15;

        if ($totalUnits >= $threshold) {
            return;
        }

        // Cheapest available unit (type 1 = basic infantry).
        $units = $this->db->exec(
            'SELECT type, input, duration FROM units
             WHERE faction = ? ORDER BY type ASC LIMIT 3',
            [$factionId]
        ) ?: [];

        $res = DataParser::toFloatArray((string) $town['resources']);

        foreach ($units as $unit) {
            $unitType = (int) $unit['type'];
            $cost     = DataParser::toFloatArray((string) ($unit['input'] ?? ''));
            $qty      = self::TRAIN_BATCH;

            // Check affordability for requested batch; fall back to 1.
            if (!$this->canAffordMultiple($res, $cost, $qty)) {
                $qty = 1;
                if (!$this->canAffordMultiple($res, $cost, 1)) {
                    continue;
                }
            }

            $armyService = new ArmyService($this->db);
            $armyService->queueTraining($townId, $unitType, $qty, (string) ($unit['duration'] ?? '0:5'));
            break;
        }
    }

    // -------------------------------------------------------------------------
    // Personality actions
    // -------------------------------------------------------------------------

    private function doPersonalityAction(array $bot, array $town, string $profile): void
    {
        $townId = (int) $town['id'];
        $botId  = (int) $bot['id'];

        $aggression = $this->aggressionLevel(); // 1–5

        switch ($profile) {
            case 'warrior':
                // Primary: barbarian camps.
                // When aggression is 3+, also raids player towns (chance = aggression/5).
                if ($aggression >= 3 && rand(1, 5) <= $aggression) {
                    $this->raidPlayerTown($townId, $town, $botId);
                } else {
                    $this->attackBarbarianCamp($townId, $town);
                }
                break;

            case 'raider':
                // Primarily targets player towns; falls back to camps if no players exist.
                if (!$this->raidPlayerTown($townId, $town, $botId)) {
                    $this->attackBarbarianCamp($townId, $town);
                }
                break;

            case 'trader':
                $this->postTradeOffer($townId, $town);
                break;

            case 'diplomat':
                $this->tryJoinAlliance($bot);
                break;

            case 'balanced':
                // 50 % camps / 50 % player raids; only acts based on aggression.
                if ($this->shouldActBasedOnAggression()) {
                    if (rand(0, 1) === 1) {
                        $this->raidPlayerTown($townId, $town, $botId);
                    } else {
                        $this->attackBarbarianCamp($townId, $town);
                    }
                }
                break;

            default: // builder — no combat action
                break;
        }
    }

    private function attackBarbarianCamp(int $townId, array $town): void
    {
        if ($this->hasPendingMovement($townId)) {
            return;
        }

        $army = DataParser::toIntArray((string) $town['army']);
        if (array_sum($army) < self::MIN_ARMY_TO_ATTACK) {
            return;
        }

        $camp = $this->db->exec(
            'SELECT id FROM barbarian_camps WHERE active = 1 ORDER BY level ASC, RAND() LIMIT 1'
        );

        if (empty($camp)) {
            return;
        }

        $campId    = (int) $camp[0]['id'];
        $targetId  = BarbarianService::CAMP_OFFSET + $campId;

        // Send only ATTACK_RATIO of the army so a garrison always stays home.
        $attackArmy = array_map(static fn($n) => (int) floor($n * self::ATTACK_RATIO), $army);
        if (array_sum($attackArmy) < self::MIN_ARMY_TO_ATTACK) {
            return;
        }

        (new ArmyService($this->db))->dispatch(
            $townId,
            $targetId,
            2, // Attack
            $attackArmy,
            [0, 0, 0, 0, 0],
            rand(300, 900) // 5–15 min travel
        );
    }

    /**
     * Raid a random non-bot player town.
     *
     * @return bool True if a raid was actually dispatched.
     */
    private function raidPlayerTown(int $townId, array $town, int $botId): bool
    {
        if ($this->hasPendingMovement($townId)) {
            return false;
        }

        $army = DataParser::toIntArray((string) $town['army']);
        // Require at least 2× minimum so enough stay home as garrison.
        if (array_sum($army) < self::MIN_ARMY_TO_ATTACK * 2) {
            return false;
        }

        // Target a random active, non-bot player town.
        $target = $this->db->exec(
            'SELECT t.id FROM towns t
             JOIN users u ON u.id = t.owner
             WHERE u.is_bot = 0 AND u.level = 1 AND t.owner != ?
             ORDER BY RAND()
             LIMIT 1',
            [$botId]
        );

        if (empty($target)) {
            return false;
        }

        // Send only ATTACK_RATIO – keeps a strong garrison home.
        $attackArmy = array_map(static fn($n) => (int) floor($n * self::ATTACK_RATIO), $army);
        if (array_sum($attackArmy) < self::MIN_ARMY_TO_ATTACK) {
            return false;
        }

        (new ArmyService($this->db))->dispatch(
            $townId,
            (int) $target[0]['id'],
            1, // Raid
            $attackArmy,
            [0, 0, 0, 0, 0],
            rand(600, 1800) // 10–30 min travel
        );

        return true;
    }

    private function postTradeOffer(int $townId, array $town): void
    {
        // Skip if offers are already open for this town.
        $existing = (int) ($this->db->exec(
            'SELECT COUNT(*) AS cnt FROM t_queue WHERE seller = ? AND buyer IS NULL',
            [$townId]
        )[0]['cnt'] ?? 0);

        if ($existing > 0) {
            return;
        }

        $res = DataParser::toFloatArray((string) $town['resources']);
        $lim = DataParser::toFloatArray((string) $town['limits']);

        // Offer the resource that exceeds 60 % of its limit.
        for ($i = 0; $i <= 3; $i++) {
            $limit = $lim[$i] ?? 800;
            if ($limit <= 0 || ($res[$i] ?? 0) < $limit * 0.6) {
                continue;
            }

            $offerAmt = (int) ($res[$i] * 0.25);
            if ($offerAmt < 50) {
                continue;
            }

            $wantIdx  = ($i + 1) % 4; // Trade for the next resource type.
            $wantAmt  = (int) ($offerAmt * 0.9);

            $this->db->exec(
                'INSERT INTO t_queue
                    (seller, buyer, sType, sSubType, sQ, bType, bSubType, bQ, type, dueTime, x, y, water, maxTime)
                 VALUES (?, NULL, 0, ?, ?, 0, ?, ?, 0, NULL, 0, 0, 0, 0)',
                [$townId, $i, $offerAmt, $wantIdx, $wantAmt]
            );
            break;
        }
    }

    private function tryJoinAlliance(array $bot): void
    {
        $botId      = (int) $bot['id'];
        $allianceId = (int) ($bot['alliance'] ?? 0);

        if ($allianceId > 0) {
            return; // Already in an alliance.
        }

        $row = $this->db->exec('SELECT id FROM alliances ORDER BY RAND() LIMIT 1');
        if (!empty($row)) {
            $this->db->exec(
                'UPDATE users SET alliance = ? WHERE id = ?',
                [(int) $row[0]['id'], $botId]
            );
        }
    }

    // -------------------------------------------------------------------------
    // Town placement
    // -------------------------------------------------------------------------

    private function placeStartingTown(int $botId, string $botName, int $factionId): void
    {
        $tile = $this->db->exec(
            'SELECT x, y FROM map WHERE type IN (0, 1) ORDER BY RAND() LIMIT 1'
        );

        if (empty($tile)) {
            return;
        }

        $x = (int) $tile[0]['x'];
        $y = (int) $tile[0]['y'];

        $townService = new TownService($this->db);
        $townName    = substr(preg_replace('/[^A-Za-z0-9]/', '', $botName) ?: 'Bot', 0, 38) . 'Kent';
        $townId      = $townService->createTown($botId, $townName);

        if ($townId <= 0) {
            return;
        }

        // Place on map and set capital flag.
        $this->db->exec(
            'UPDATE map SET type = 3, subtype = ? WHERE x = ? AND y = ?',
            [$townId, $x, $y]
        );
        $this->db->exec(
            'UPDATE towns SET isCapital = 1, resources = ?, production = ?, `limits` = ? WHERE id = ?',
            [self::STARTING_RESOURCES, self::STARTING_PRODUCTION, self::STARTING_LIMITS, $townId]
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function hasPendingMovement(int $townId): bool
    {
        $cnt = (int) ($this->db->exec(
            'SELECT COUNT(*) AS cnt FROM a_queue WHERE town = ?',
            [$townId]
        )[0]['cnt'] ?? 0);

        return $cnt > 0;
    }

    /** @param list<float> $res @param list<float> $cost */
    private function canAffordMultiple(array $res, array $cost, int $qty): bool
    {
        foreach ($cost as $i => $c) {
            if (($res[$i] ?? 0) < $c * $qty) {
                return false;
            }
        }
        return true;
    }

    private function shouldActBasedOnAggression(): bool
    {
        $aggression = $this->aggressionLevel(); // 1–5
        return rand(1, 5) <= $aggression;
    }

    private function profileDescription(string $profile): string
    {
        return match ($profile) {
            'builder'  => 'A peaceful settler focused on building a prosperous empire.',
            'warrior'  => 'A fearless warrior seeking glory on the battlefield.',
            'raider'   => 'A ruthless raider who takes what they want.',
            'trader'   => 'A cunning merchant who profits from every deal.',
            'diplomat' => 'A skilled diplomat building alliances across the realm.',
            default    => 'An adventurer seeking fame and fortune.',
        };
    }
}

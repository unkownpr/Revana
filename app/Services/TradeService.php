<?php declare(strict_types=1);

namespace Devana\Services;

use Devana\Helpers\DataParser;

final class TradeService
{
    private \DB\SQL $db;

    public function __construct(\DB\SQL $db)
    {
        $this->db = $db;
    }

    /**
     * Create a trade offer.
     */
    public function createOffer(
        int $sellerId,
        int $sellType,
        int $sellSubType,
        int $sellQuantity,
        int $buyType,
        int $buySubType,
        int $buyQuantity
    ): void {
        if ($sellQuantity <= 0 || $buyQuantity <= 0) {
            throw new \RuntimeException('Quantity must be positive.');
        }

        $this->lockTradeTables();
        try {
            $this->ensureHasGoods($sellerId, $sellType, $sellSubType, $sellQuantity);
            $this->db->exec(
                'INSERT INTO t_queue (seller, buyer, sType, sSubType, sQ, bType, bSubType, bQ, type, dueTime, x, y, water, maxTime) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, 0, NULL, 0, 0, 0, 0)',
                [$sellerId, $sellType, $sellSubType, $sellQuantity, $buyType, $buySubType, $buyQuantity]
            );
            $this->reserveGoods($sellerId, $sellType, $sellSubType, $sellQuantity);
        } catch (\Throwable $e) {
            throw $e;
        } finally {
            $this->unlockTables();
        }
    }

    /**
     * Accept a trade offer.
     */
    public function acceptOffer(
        int $sellerId,
        int $buyerId,
        int $sType,
        int $sSubType,
        int $bType,
        int $bSubType,
        int $travelSeconds,
        bool $isWater = false
    ): void {
        $this->lockTradeTables();
        try {
            $row = $this->db->exec(
                'SELECT bQ FROM t_queue WHERE seller = ? AND sType = ? AND sSubType = ? AND bType = ? AND bSubType = ? AND type = 0 AND buyer IS NULL',
                [$sellerId, $sType, $sSubType, $bType, $bSubType]
            );
            if (empty($row)) {
                throw new \RuntimeException('offerNotAvailable');
            }

            $buyerQuantity = (int) ($row[0]['bQ'] ?? 0);
            $this->reserveGoods($buyerId, $bType, $bSubType, $buyerQuantity);

            $this->db->exec(
                'UPDATE t_queue SET buyer = ?, type = 1, dueTime = DATE_ADD(NOW(), INTERVAL ? SECOND), water = ?, maxTime = ? WHERE seller = ? AND sType = ? AND sSubType = ? AND bType = ? AND bSubType = ? AND type = 0 AND buyer IS NULL',
                [$buyerId, $travelSeconds, $isWater ? 1 : 0, $travelSeconds, $sellerId, $sType, $sSubType, $bType, $bSubType]
            );
        } finally {
            $this->unlockTables();
        }
    }

    public function createDirectTransfer(
        int $sellerId,
        int $buyerId,
        int $resourceType,
        int $quantity,
        int $travelSeconds,
        bool $isWater = false
    ): void {
        if ($quantity <= 0) {
            throw new \RuntimeException('Quantity must be positive.');
        }

        if ($sellerId === $buyerId) {
            throw new \RuntimeException('cannotSendSameTown');
        }

        $nonce = random_int(1, 2147483647);

        $this->lockTradeTables();
        try {
            $this->ensureHasGoods($sellerId, 0, $resourceType, $quantity);
            $this->db->exec(
                'INSERT INTO t_queue (seller, buyer, sType, sSubType, sQ, bType, bSubType, bQ, type, dueTime, x, y, water, maxTime)
                 VALUES (?, ?, 0, ?, ?, ?, ?, 0, 2, DATE_ADD(NOW(), INTERVAL ? SECOND), 0, 0, ?, ?)',
                [$sellerId, $buyerId, $resourceType, $quantity, $buyerId, $nonce, $travelSeconds, $isWater ? 1 : 0, $travelSeconds]
            );
            $this->reserveGoods($sellerId, 0, $resourceType, $quantity);
        } finally {
            $this->unlockTables();
        }
    }

    /**
     * Get available merchants for a town.
     */
    public function getAvailableMerchants(int $townId, int $marketLevel): int
    {
        $merchantsTotal = max(1, $marketLevel);

        $active = $this->db->exec(
            'SELECT COUNT(*) AS cnt FROM t_queue WHERE seller = ? AND type IN (1, 2)',
            [$townId]
        );
        $open = $this->db->exec(
            'SELECT COUNT(*) AS cnt FROM t_queue WHERE seller = ? AND type = 0',
            [$townId]
        );

        $busy = (int) ($active[0]['cnt'] ?? 0) + (int) ($open[0]['cnt'] ?? 0);

        return max(0, $merchantsTotal - $busy);
    }

    /**
     * @return array{offers: array<int, array<string, mixed>>, total: int, page: int, per_page: int, total_pages: int}
     */
    public function getOpenOffers(
        ?int $sType = null,
        ?int $sSubType = null,
        ?int $bType = null,
        ?int $bSubType = null,
        int $page = 1,
        int $perPage = 10,
        ?int $excludeSellerId = null
    ): array
    {
        $where = 'WHERE type = 0 AND buyer IS NULL';
        $params = [];

        if ($sType !== null) {
            $where .= ' AND sType = ?';
            $params[] = $sType;
        }
        if ($sSubType !== null) {
            $where .= ' AND sSubType = ?';
            $params[] = $sSubType;
        }
        if ($bType !== null) {
            $where .= ' AND bType = ?';
            $params[] = $bType;
        }
        if ($bSubType !== null) {
            $where .= ' AND bSubType = ?';
            $params[] = $bSubType;
        }
        if ($excludeSellerId !== null) {
            $where .= ' AND seller <> ?';
            $params[] = $excludeSellerId;
        }

        $countResult = $this->db->exec('SELECT COUNT(*) AS cnt FROM t_queue ' . $where, $params);
        $total = (int) ($countResult[0]['cnt'] ?? 0);

        $page = max(1, $page);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $offset = ($page - 1) * $perPage;

        $params[] = $perPage;
        $params[] = $offset;

        $offers = $this->db->exec(
            'SELECT t_queue.*, users.id AS seller_user_id, users.name AS seller_name
             FROM t_queue
             LEFT JOIN towns ON towns.id = t_queue.seller
             LEFT JOIN users ON users.id = towns.owner
             ' . $where . '
             ORDER BY t_queue.seller
             LIMIT ? OFFSET ?',
            $params
        );

        return [
            'offers' => $offers,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getOffersBySeller(int $sellerId): array
    {
        return $this->db->exec(
            'SELECT * FROM t_queue WHERE seller = ? AND type IN (0, 1) ORDER BY type, dueTime',
            [$sellerId]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getActiveTransfersBySeller(int $sellerId): array
    {
        return $this->db->exec(
            'SELECT t_queue.sType, t_queue.sSubType, t_queue.sQ, t_queue.dueTime, TIMEDIFF(t_queue.dueTime, NOW()) AS timeLeft, t_queue.water, towns.name AS target_name
             FROM t_queue
             LEFT JOIN towns ON towns.id = t_queue.buyer
             WHERE t_queue.seller = ? AND t_queue.type = 2
             ORDER BY t_queue.dueTime ASC',
            [$sellerId]
        );
    }

    private function reserveGoods(int $townId, int $type, int $subType, int $quantity): void
    {
        if ($quantity <= 0) {
            throw new \RuntimeException('Quantity must be positive.');
        }

        $town = $this->db->exec('SELECT resources, weapons FROM towns WHERE id = ?', [$townId]);
        if (empty($town)) {
            throw new \RuntimeException('Town not found.');
        }

        if ($type === 0) {
            $resources = DataParser::toFloatArray($town[0]['resources']);
            $current = (float) ($resources[$subType] ?? 0);
            if ($current < $quantity) {
                throw new \RuntimeException('notEnoughRes');
            }
            $resources[$subType] = $current - $quantity;
            $this->db->exec(
                'UPDATE towns SET resources = ? WHERE id = ?',
                [DataParser::serializeRounded($resources), $townId]
            );
            return;
        }

        $weapons = DataParser::toIntArray($town[0]['weapons']);
        $current = (int) ($weapons[$subType] ?? 0);
        if ($current < $quantity) {
            throw new \RuntimeException('notEnoughWeapons');
        }
        $weapons[$subType] = $current - $quantity;
        $this->db->exec(
            'UPDATE towns SET weapons = ? WHERE id = ?',
            [DataParser::serialize($weapons), $townId]
        );
    }

    private function ensureHasGoods(int $townId, int $type, int $subType, int $quantity): void
    {
        if ($quantity <= 0) {
            throw new \RuntimeException('Quantity must be positive.');
        }

        $town = $this->db->exec('SELECT resources, weapons FROM towns WHERE id = ?', [$townId]);
        if (empty($town)) {
            throw new \RuntimeException('Town not found.');
        }

        if ($type === 0) {
            $resources = DataParser::toFloatArray($town[0]['resources']);
            $current = (float) ($resources[$subType] ?? 0);
            if ($current < $quantity) {
                throw new \RuntimeException('notEnoughRes');
            }
            return;
        }

        $weapons = DataParser::toIntArray($town[0]['weapons']);
        $current = (int) ($weapons[$subType] ?? 0);
        if ($current < $quantity) {
            throw new \RuntimeException('notEnoughWeapons');
        }
    }

    private function lockTradeTables(): void
    {
        $this->db->exec('LOCK TABLES t_queue WRITE, towns WRITE');
    }

    private function unlockTables(): void
    {
        $this->db->exec('UNLOCK TABLES');
    }
}

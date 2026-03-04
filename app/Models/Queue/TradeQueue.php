<?php

declare(strict_types=1);

namespace Devana\Models\Queue;

final class TradeQueue extends \DB\SQL\Mapper
{
    private \DB\SQL $database;

    public function __construct(\DB\SQL $db)
    {
        parent::__construct($db, 't_queue');
        $this->database = $db;
    }

    public function findBySeller(int $sellerId): array
    {
        return $this->database->exec(
            'SELECT * FROM t_queue WHERE seller = ? ORDER BY dueTime ASC',
            [$sellerId]
        );
    }

    public function findByBuyer(int $buyerId): array
    {
        return $this->database->exec(
            'SELECT * FROM t_queue WHERE buyer = ? ORDER BY dueTime ASC',
            [$buyerId]
        );
    }
}

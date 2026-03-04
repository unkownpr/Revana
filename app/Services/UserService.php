<?php declare(strict_types=1);

namespace Devana\Services;

use Devana\Helpers\DataParser;

final class UserService
{
    private \DB\SQL $db;

    public function __construct(\DB\SQL $db)
    {
        $this->db = $db;
    }

    /**
     * Recalculate user points from total building levels across all towns.
     */
    public function recalculatePoints(int $userId): void
    {
        $allTowns = $this->db->exec('SELECT buildings FROM towns WHERE owner = ?', [$userId]);
        $points = 0;
        foreach ($allTowns as $t) {
            $points += array_sum(DataParser::toIntArray($t['buildings']));
        }
        $this->db->exec('UPDATE users SET points = ? WHERE id = ?', [$points, $userId]);
    }
}

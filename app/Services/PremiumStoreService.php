<?php declare(strict_types=1);

namespace Devana\Services;

final class PremiumStoreService
{
    private \DB\SQL $db;
    private ?bool $hasUsersPremiumBalanceColumn = null;

    public function __construct(\DB\SQL $db)
    {
        $this->db = $db;
    }

    public function bootstrapCatalog(): void
    {
        $this->ensureRequestTable();

        $this->db->exec(
            "INSERT IGNORE INTO premium_packages (code, name, credits, price_usd_cents, is_active, sort_order) VALUES
             ('starter_pack', 'Starter Pack', 250, 499, 1, 10),
             ('war_chest', 'War Chest', 700, 1199, 1, 20),
             ('royal_treasury', 'Royal Treasury', 1500, 2199, 1, 30)"
        );

        $this->db->exec(
            "INSERT IGNORE INTO premium_products (code, name, description, slot, rarity, price_credits, icon, is_active, sort_order) VALUES
             ('badge_founder', 'Founder Badge', 'Displayed near your profile name.', 'profile_badge', 'rare', 180, 'badge', 1, 10),
             ('badge_warlord', 'Warlord Badge', 'Displayed near your profile name.', 'profile_badge', 'epic', 260, 'badge', 1, 20),
             ('frame_amber', 'Amber Frame', 'Decorative avatar frame in profile.', 'profile_frame', 'rare', 220, 'frame', 1, 30),
             ('frame_obsidian', 'Obsidian Frame', 'Decorative avatar frame in profile.', 'profile_frame', 'epic', 340, 'frame', 1, 40),
             ('title_wayfarer', 'Title: Wayfarer', 'Adds a cosmetic title badge.', 'profile_title', 'common', 140, 'title', 1, 50),
             ('title_high_king', 'Title: High King', 'Adds a cosmetic title badge.', 'profile_title', 'legendary', 420, 'title', 1, 60)"
        );
    }

    public function requestPackagePurchase(int $userId, int $packageId, string $note = ''): array
    {
        $this->ensureRequestTable();

        $rows = $this->db->exec(
            'SELECT id, code, name, price_usd_cents
             FROM premium_packages
             WHERE id = ? AND is_active = 1
             LIMIT 1',
            [$packageId]
        );
        if (empty($rows)) {
            throw new \RuntimeException('packageNotFound');
        }
        $pkg = $rows[0];

        $dup = $this->db->exec(
            "SELECT id
             FROM premium_purchase_requests
             WHERE user_id = ? AND request_type = 'package' AND target_id = ? AND status = 'pending'
             LIMIT 1",
            [$userId, $packageId]
        );
        if (!empty($dup)) {
            throw new \RuntimeException('requestAlreadyExists');
        }

        $this->db->exec(
            "INSERT INTO premium_purchase_requests
             (user_id, request_type, target_id, target_code, target_name, price_credits, price_usd_cents, status, note, created_at)
             VALUES (?, 'package', ?, ?, ?, 0, ?, 'pending', ?, NOW())",
            [
                $userId,
                $packageId,
                (string) ($pkg['code'] ?? ''),
                (string) ($pkg['name'] ?? ''),
                max(0, (int) ($pkg['price_usd_cents'] ?? 0)),
                substr(trim($note), 0, 250),
            ]
        );

        return $pkg;
    }

    public function getActivePackageById(int $packageId): ?array
    {
        $rows = $this->db->exec(
            'SELECT id, code, name, credits, price_usd_cents
             FROM premium_packages
             WHERE id = ? AND is_active = 1
             LIMIT 1',
            [$packageId]
        );
        return $rows[0] ?? null;
    }

    public function requestProductPurchase(int $userId, int $productId): array
    {
        $this->ensureRequestTable();

        $rows = $this->db->exec(
            'SELECT id, code, name, price_credits
             FROM premium_products
             WHERE id = ? AND is_active = 1
             LIMIT 1',
            [$productId]
        );
        if (empty($rows)) {
            throw new \RuntimeException('productNotFound');
        }
        $prd = $rows[0];

        $owned = $this->db->exec(
            'SELECT id FROM user_inventory WHERE user_id = ? AND product_id = ? LIMIT 1',
            [$userId, $productId]
        );
        if (!empty($owned)) {
            throw new \RuntimeException('alreadyOwned');
        }

        $dup = $this->db->exec(
            "SELECT id
             FROM premium_purchase_requests
             WHERE user_id = ? AND request_type = 'product' AND target_id = ? AND status = 'pending'
             LIMIT 1",
            [$userId, $productId]
        );
        if (!empty($dup)) {
            throw new \RuntimeException('requestAlreadyExists');
        }

        $this->db->exec(
            "INSERT INTO premium_purchase_requests
             (user_id, request_type, target_id, target_code, target_name, price_credits, price_usd_cents, status, note, created_at)
             VALUES (?, 'product', ?, ?, ?, ?, 0, 'pending', '', NOW())",
            [
                $userId,
                $productId,
                (string) ($prd['code'] ?? ''),
                (string) ($prd['name'] ?? ''),
                max(0, (int) ($prd['price_credits'] ?? 0)),
            ]
        );

        return $prd;
    }

    /**
     * @return array{balance:int, packages:array<int,array<string,mixed>>, products:array<int,array<string,mixed>>}
     */
    public function getStoreData(int $userId): array
    {
        $this->bootstrapCatalog();

        $packages = $this->db->exec(
            'SELECT id, code, name, credits, price_usd_cents, sort_order
             FROM premium_packages
             WHERE is_active = 1
             ORDER BY sort_order ASC, id ASC'
        );

        $products = $this->db->exec(
            'SELECT p.id, p.code, p.name, p.description, p.slot, p.rarity, p.price_credits, p.icon, p.sort_order,
                    CASE WHEN i.id IS NULL THEN 0 ELSE 1 END AS owned,
                    CASE WHEN i.is_active = 1 THEN 1 ELSE 0 END AS active
             FROM premium_products p
             LEFT JOIN user_inventory i ON i.product_id = p.id AND i.user_id = ?
             WHERE p.is_active = 1
             ORDER BY p.sort_order ASC, p.id ASC',
            [$userId]
        );

        return [
            'balance' => $this->getBalance($userId),
            'packages' => $packages,
            'products' => $products,
        ];
    }

    /**
     * @return array{balance:int, added:int, package:array<string,mixed>}
     */
    public function purchasePackage(int $userId, int $packageId): array
    {
        $pkgRows = $this->db->exec(
            'SELECT id, code, name, credits FROM premium_packages WHERE id = ? AND is_active = 1 LIMIT 1',
            [$packageId]
        );
        if (empty($pkgRows)) {
            throw new \RuntimeException('packageNotFound');
        }

        $pkg = $pkgRows[0];
        $add = max(0, (int) ($pkg['credits'] ?? 0));
        if ($add <= 0) {
            throw new \RuntimeException('invalidPackage');
        }

        $newBalance = $this->getBalance($userId) + $add;
        $this->setBalance($userId, $newBalance);
        $this->addTransaction($userId, 'package_purchase', $add, $newBalance, 'package:' . (string) ($pkg['code'] ?? 'unknown'));

        return [
            'balance' => $newBalance,
            'added' => $add,
            'package' => $pkg,
        ];
    }

    /**
     * @return array{balance:int, product:array<string,mixed>}
     */
    public function purchaseProduct(int $userId, int $productId): array
    {
        $rows = $this->db->exec(
            'SELECT id, code, name, slot, price_credits
             FROM premium_products
             WHERE id = ? AND is_active = 1
             LIMIT 1',
            [$productId]
        );
        if (empty($rows)) {
            throw new \RuntimeException('productNotFound');
        }
        $product = $rows[0];

        $owned = $this->db->exec(
            'SELECT id FROM user_inventory WHERE user_id = ? AND product_id = ? LIMIT 1',
            [$userId, $productId]
        );
        if (!empty($owned)) {
            throw new \RuntimeException('alreadyOwned');
        }

        $price = max(0, (int) ($product['price_credits'] ?? 0));
        $balance = $this->getBalance($userId);
        if ($balance < $price) {
            throw new \RuntimeException('notEnoughPremium');
        }

        $newBalance = $balance - $price;
        $this->setBalance($userId, $newBalance);
        $this->db->exec(
            "INSERT INTO user_inventory (user_id, product_id, acquired_at, acquire_type, is_active)
             VALUES (?, ?, NOW(), 'purchase', 0)",
            [$userId, $productId]
        );
        $this->addTransaction($userId, 'product_purchase', -$price, $newBalance, 'product:' . (string) ($product['code'] ?? 'unknown'));

        return [
            'balance' => $newBalance,
            'product' => $product,
        ];
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    public function getInventory(int $userId): array
    {
        return $this->db->exec(
            'SELECT i.id AS inventory_id, i.product_id, i.acquired_at, i.is_active,
                    p.code, p.name, p.description, p.slot, p.rarity, p.icon, p.price_credits
             FROM user_inventory i
             INNER JOIN premium_products p ON p.id = i.product_id
             WHERE i.user_id = ?
             ORDER BY p.slot ASC, p.sort_order ASC, p.id ASC',
            [$userId]
        );
    }

    public function activateProduct(int $userId, int $productId): void
    {
        $rows = $this->db->exec(
            'SELECT i.id, i.product_id, p.slot
             FROM user_inventory i
             INNER JOIN premium_products p ON p.id = i.product_id
             WHERE i.user_id = ? AND i.product_id = ?
             LIMIT 1',
            [$userId, $productId]
        );
        if (empty($rows)) {
            throw new \RuntimeException('inventoryItemNotFound');
        }

        $slot = (string) ($rows[0]['slot'] ?? '');
        if ($slot === '') {
            throw new \RuntimeException('invalidSlot');
        }

        $slotRows = $this->db->exec(
            'SELECT i.product_id
             FROM user_inventory i
             INNER JOIN premium_products p ON p.id = i.product_id
             WHERE i.user_id = ? AND p.slot = ?',
            [$userId, $slot]
        );

        $productIds = [];
        foreach ($slotRows as $slotRow) {
            $productIds[] = (int) ($slotRow['product_id'] ?? 0);
        }

        if (!empty($productIds)) {
            $in = implode(',', array_map('intval', $productIds));
            $this->db->exec(
                "UPDATE user_inventory SET is_active = 0 WHERE user_id = ? AND product_id IN ({$in})",
                [$userId]
            );
        }

        $this->db->exec(
            'UPDATE user_inventory SET is_active = 1 WHERE user_id = ? AND product_id = ?',
            [$userId, $productId]
        );
    }

    /**
     * @return array<string, array<string,mixed>>
     */
    public function getActiveCosmetics(int $userId): array
    {
        $rows = $this->db->exec(
            'SELECT p.slot, p.code, p.name, p.icon
             FROM user_inventory i
             INNER JOIN premium_products p ON p.id = i.product_id
             WHERE i.user_id = ? AND i.is_active = 1',
            [$userId]
        );

        $out = [];
        foreach ($rows as $row) {
            $slot = (string) ($row['slot'] ?? '');
            if ($slot === '') continue;
            $out[$slot] = $row;
        }

        return $out;
    }

    private function getBalance(int $userId): int
    {
        if (!$this->hasUsersPremiumBalanceColumn()) {
            return 0;
        }
        $rows = $this->db->exec('SELECT premium_balance FROM users WHERE id = ? LIMIT 1', [$userId]);
        return max(0, (int) ($rows[0]['premium_balance'] ?? 0));
    }

    private function setBalance(int $userId, int $newBalance): void
    {
        if (!$this->hasUsersPremiumBalanceColumn()) {
            return;
        }
        $this->db->exec(
            'UPDATE users SET premium_balance = ? WHERE id = ?',
            [max(0, $newBalance), $userId]
        );
    }

    private function hasUsersPremiumBalanceColumn(): bool
    {
        if ($this->hasUsersPremiumBalanceColumn !== null) {
            return $this->hasUsersPremiumBalanceColumn;
        }

        try {
            $rows = $this->db->exec("SHOW COLUMNS FROM `users` LIKE 'premium_balance'");
            $this->hasUsersPremiumBalanceColumn = !empty($rows);
        } catch (\Throwable $e) {
            $this->hasUsersPremiumBalanceColumn = false;
        }

        return $this->hasUsersPremiumBalanceColumn;
    }

    private function addTransaction(int $userId, string $type, int $amount, int $balanceAfter, string $note): void
    {
        $this->db->exec(
            'INSERT INTO premium_transactions (user_id, txn_type, amount, balance_after, note, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())',
            [$userId, $type, $amount, $balanceAfter, substr($note, 0, 250)]
        );
    }

    private function ensureRequestTable(): void
    {
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS `premium_purchase_requests` (
                `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `user_id`           INT UNSIGNED NOT NULL,
                `request_type`      VARCHAR(24)  NOT NULL,
                `target_id`         INT UNSIGNED NOT NULL,
                `target_code`       VARCHAR(64)  NOT NULL DEFAULT '',
                `target_name`       VARCHAR(128) NOT NULL DEFAULT '',
                `price_credits`     INT UNSIGNED NOT NULL DEFAULT 0,
                `price_usd_cents`   INT UNSIGNED NOT NULL DEFAULT 0,
                `status`            VARCHAR(16)  NOT NULL DEFAULT 'pending',
                `note`              VARCHAR(255) NOT NULL DEFAULT '',
                `created_at`        DATETIME     NOT NULL,
                `reviewed_by`       INT UNSIGNED DEFAULT NULL,
                `review_note`       VARCHAR(255) NOT NULL DEFAULT '',
                `reviewed_at`       DATETIME DEFAULT NULL,
                KEY `user_status_created` (`user_id`, `status`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=latin1"
        );
    }
}

<?php declare(strict_types=1);

namespace Devana\Services;

final class MigrationService
{
    /**
     * @var array<string, array{check_sql: string, sql: string}>
     */
    private const MIGRATIONS = [
        '001_users_pass_hash_len' => [
            'check_sql' => "SELECT CHARACTER_MAXIMUM_LENGTH AS len FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'pass'",
            'sql' => "ALTER TABLE `users` MODIFY COLUMN `pass` VARBINARY(256) NOT NULL",
        ],
        '002_towns_layout' => [
            'check_sql' => "SHOW COLUMNS FROM `towns` LIKE 'layout'",
            'sql' => "ALTER TABLE `towns` ADD COLUMN `layout` TEXT DEFAULT NULL AFTER `water`",
        ],
        '003_users_chat_last_seen' => [
            'check_sql' => "SHOW COLUMNS FROM `users` LIKE 'chat_last_seen'",
            'sql' => "ALTER TABLE `users` ADD COLUMN `chat_last_seen` DATETIME DEFAULT NULL",
        ],
        '004_chat_id' => [
            'check_sql' => "SHOW COLUMNS FROM `chat` LIKE 'id'",
            'sql' => "ALTER TABLE `chat` ADD COLUMN `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST",
        ],
        '005_users_avatar_seed' => [
            'check_sql' => "SHOW COLUMNS FROM `users` LIKE 'avatar_seed'",
            'sql' => "ALTER TABLE `users` ADD COLUMN `avatar_seed` VARCHAR(128) DEFAULT NULL AFTER `lang`",
        ],
        '006_users_avatar_style_options' => [
            'check_sql' => "SHOW COLUMNS FROM `users` LIKE 'avatar_style'",
            'sql' => "ALTER TABLE `users` ADD COLUMN `avatar_style` VARCHAR(32) NOT NULL DEFAULT 'pixel-art' AFTER `avatar_seed`, ADD COLUMN `avatar_options` TEXT NULL AFTER `avatar_style`",
        ],
        '007_preferences_table' => [
            'check_sql' => "SHOW TABLES LIKE 'preferences'",
            'sql' => "CREATE TABLE `preferences` (
                `user` INT(10) UNSIGNED NOT NULL,
                `name` VARCHAR(32) NOT NULL,
                `value` VARCHAR(64) NOT NULL,
                PRIMARY KEY (`user`, `name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=latin1",
        ],
        '008_blocklist_table' => [
            'check_sql' => "SHOW TABLES LIKE 'blocklist'",
            'sql' => "CREATE TABLE `blocklist` (
                `recipient` INT(10) UNSIGNED NOT NULL,
                `sender` INT(10) UNSIGNED NOT NULL,
                PRIMARY KEY (`recipient`, `sender`)
            ) ENGINE=InnoDB DEFAULT CHARSET=latin1",
        ],
        '009_users_xp' => [
            'check_sql' => "SHOW COLUMNS FROM `users` LIKE 'xp'",
            'sql' => "ALTER TABLE `users` ADD COLUMN `xp` INT(10) UNSIGNED NOT NULL DEFAULT 0",
        ],
        '010_player_missions' => [
            'check_sql' => "SHOW TABLES LIKE 'player_missions'",
            'sql' => "CREATE TABLE `player_missions` (
                `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `user`      INT UNSIGNED NOT NULL,
                `date`      DATE NOT NULL,
                `type`      TINYINT UNSIGNED NOT NULL,
                `target`    SMALLINT UNSIGNED NOT NULL,
                `progress`  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `claimed`   TINYINT(1) NOT NULL DEFAULT 0,
                UNIQUE KEY `user_date_type` (`user`, `date`, `type`)
            ) ENGINE=InnoDB DEFAULT CHARSET=latin1",
        ],
        '011_barbarian_camps' => [
            'check_sql' => "SHOW TABLES LIKE 'barbarian_camps'",
            'sql' => "CREATE TABLE `barbarian_camps` (
                `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `x`           SMALLINT NOT NULL,
                `y`           SMALLINT NOT NULL,
                `level`       TINYINT UNSIGNED NOT NULL DEFAULT 1,
                `army`        VARCHAR(128) NOT NULL DEFAULT '0-3-2-0-0-0-0-0-0-0-0-0-0',
                `resources`   VARCHAR(64)  NOT NULL DEFAULT '100-100-100-100-50',
                `weapons`     VARCHAR(64)  NOT NULL DEFAULT '0-0-0-0-0-0-0-0-0-0-0',
                `active`      TINYINT(1)   NOT NULL DEFAULT 1,
                `respawn_at`  DATETIME     DEFAULT NULL,
                UNIQUE KEY `xy` (`x`, `y`)
            ) ENGINE=InnoDB DEFAULT CHARSET=latin1",
        ],
        '012_bot_system' => [
            'check_sql' => "SHOW COLUMNS FROM `users` LIKE 'is_bot'",
            'sql' => "ALTER TABLE `users`
                ADD COLUMN `is_bot`      TINYINT(1)   NOT NULL DEFAULT 0 AFTER `lang`,
                ADD COLUMN `bot_profile` VARCHAR(20)  NOT NULL DEFAULT 'balanced' AFTER `is_bot`",
        ],
        '013_mission_templates' => [
            'check_sql' => "SHOW TABLES LIKE 'mission_templates'",
            'sql' => "CREATE TABLE `mission_templates` (
                `id`                     INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `type`                   TINYINT UNSIGNED NOT NULL,
                `title_tr`               VARCHAR(160) NOT NULL,
                `title_en`               VARCHAR(160) NOT NULL,
                `target_min`             SMALLINT UNSIGNED NOT NULL DEFAULT 1,
                `target_max`             SMALLINT UNSIGNED NOT NULL DEFAULT 1,
                `reward_xp`              INT UNSIGNED NOT NULL DEFAULT 100,
                `reward_resource_index`  TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `reward_resource_amount` INT UNSIGNED NOT NULL DEFAULT 200,
                `is_active`              TINYINT(1) NOT NULL DEFAULT 1,
                `sort_order`             INT UNSIGNED NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=latin1",
        ],
        '014_player_missions_template_id' => [
            'check_sql' => "SHOW COLUMNS FROM `player_missions` LIKE 'template_id'",
            'sql' => "ALTER TABLE `player_missions`
                ADD COLUMN `template_id` INT UNSIGNED NULL AFTER `type`,
                ADD KEY `template_id` (`template_id`)",
        ],
        '015_users_premium_balance' => [
            'check_sql' => "SHOW COLUMNS FROM `users` LIKE 'premium_balance'",
            'sql' => "ALTER TABLE `users` ADD COLUMN `premium_balance` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `points`",
        ],
        '016_premium_packages_table' => [
            'check_sql' => "SHOW TABLES LIKE 'premium_packages'",
            'sql' => "CREATE TABLE `premium_packages` (
                `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `code`             VARCHAR(64)  NOT NULL,
                `name`             VARCHAR(128) NOT NULL,
                `credits`          INT UNSIGNED NOT NULL DEFAULT 0,
                `price_usd_cents`  INT UNSIGNED NOT NULL DEFAULT 0,
                `is_active`        TINYINT(1)   NOT NULL DEFAULT 1,
                `sort_order`       INT UNSIGNED NOT NULL DEFAULT 0,
                UNIQUE KEY `uniq_code` (`code`)
            ) ENGINE=InnoDB DEFAULT CHARSET=latin1",
        ],
        '017_premium_products_table' => [
            'check_sql' => "SHOW TABLES LIKE 'premium_products'",
            'sql' => "CREATE TABLE `premium_products` (
                `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `code`          VARCHAR(64)  NOT NULL,
                `name`          VARCHAR(128) NOT NULL,
                `description`   VARCHAR(255) NOT NULL DEFAULT '',
                `slot`          VARCHAR(32)  NOT NULL,
                `rarity`        VARCHAR(24)  NOT NULL DEFAULT 'common',
                `price_credits` INT UNSIGNED NOT NULL DEFAULT 0,
                `icon`          VARCHAR(32)  NOT NULL DEFAULT '',
                `is_active`     TINYINT(1)   NOT NULL DEFAULT 1,
                `sort_order`    INT UNSIGNED NOT NULL DEFAULT 0,
                UNIQUE KEY `uniq_code` (`code`)
            ) ENGINE=InnoDB DEFAULT CHARSET=latin1",
        ],
        '018_premium_transactions_table' => [
            'check_sql' => "SHOW TABLES LIKE 'premium_transactions'",
            'sql' => "CREATE TABLE `premium_transactions` (
                `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `user_id`       INT UNSIGNED NOT NULL,
                `txn_type`      VARCHAR(32)  NOT NULL,
                `amount`        INT          NOT NULL DEFAULT 0,
                `balance_after` INT UNSIGNED NOT NULL DEFAULT 0,
                `note`          VARCHAR(255) NOT NULL DEFAULT '',
                `created_at`    DATETIME     NOT NULL,
                KEY `user_time` (`user_id`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=latin1",
        ],
        '019_user_inventory_table' => [
            'check_sql' => "SHOW TABLES LIKE 'user_inventory'",
            'sql' => "CREATE TABLE `user_inventory` (
                `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `user_id`      INT UNSIGNED NOT NULL,
                `product_id`   INT UNSIGNED NOT NULL,
                `acquired_at`  DATETIME     NOT NULL,
                `acquire_type` VARCHAR(32)  NOT NULL DEFAULT 'purchase',
                `is_active`    TINYINT(1)   NOT NULL DEFAULT 0,
                UNIQUE KEY `uniq_user_product` (`user_id`, `product_id`),
                KEY `user_slot` (`user_id`, `is_active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=latin1",
        ],
        '020_notifications_table' => [
            'check_sql' => "SHOW TABLES LIKE 'notifications'",
            'sql' => "CREATE TABLE `notifications` (
                `id`          INT AUTO_INCREMENT PRIMARY KEY,
                `user_id`     INT UNSIGNED NOT NULL,
                `type`        TINYINT UNSIGNED NOT NULL,
                `message_key` VARCHAR(64) NOT NULL,
                `url`         VARCHAR(128) DEFAULT NULL,
                `is_read`     TINYINT(1) NOT NULL DEFAULT 0,
                `created_at`  DATETIME NOT NULL DEFAULT NOW(),
                INDEX `idx_user_unread` (`user_id`, `is_read`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ],
        '021_users_login_streak' => [
            'check_sql' => "SHOW COLUMNS FROM `users` LIKE 'login_streak'",
            'sql' => "ALTER TABLE `users`
                ADD COLUMN `login_streak`    INT UNSIGNED NOT NULL DEFAULT 0,
                ADD COLUMN `last_login_date` DATE NULL DEFAULT NULL,
                ADD COLUMN `max_streak`      INT UNSIGNED NOT NULL DEFAULT 0",
        ],
        '022_weekly_missions_table' => [
            'check_sql' => "SHOW TABLES LIKE 'weekly_missions'",
            'sql' => "CREATE TABLE `weekly_missions` (
                `id`          INT AUTO_INCREMENT PRIMARY KEY,
                `user_id`     INT UNSIGNED NOT NULL,
                `week_start`  DATE NOT NULL,
                `type`        TINYINT UNSIGNED NOT NULL,
                `template_id` INT UNSIGNED NULL,
                `target`      INT UNSIGNED NOT NULL DEFAULT 1,
                `progress`    INT UNSIGNED NOT NULL DEFAULT 0,
                `claimed`     TINYINT(1) NOT NULL DEFAULT 0,
                UNIQUE KEY `uq_user_week_type` (`user_id`, `week_start`, `type`)
            ) ENGINE=InnoDB DEFAULT CHARSET=latin1",
        ],
        '023_achievements_table' => [
            'check_sql' => "SHOW TABLES LIKE 'achievements'",
            'sql' => "CREATE TABLE `achievements` (
                `id`         INT AUTO_INCREMENT PRIMARY KEY,
                `code`       VARCHAR(64) UNIQUE NOT NULL,
                `title_tr`   VARCHAR(128) NOT NULL,
                `title_en`   VARCHAR(128) NOT NULL,
                `desc_tr`    VARCHAR(255) DEFAULT '',
                `desc_en`    VARCHAR(255) DEFAULT '',
                `category`   TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `threshold`  INT UNSIGNED NOT NULL DEFAULT 1,
                `reward_xp`  INT UNSIGNED NOT NULL DEFAULT 0,
                `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
                `sort_order` INT UNSIGNED NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ],
        '024_player_achievements_table' => [
            'check_sql' => "SHOW TABLES LIKE 'player_achievements'",
            'sql' => "CREATE TABLE `player_achievements` (
                `user_id`        INT UNSIGNED NOT NULL,
                `achievement_id` INT UNSIGNED NOT NULL,
                `earned_at`      DATETIME NOT NULL DEFAULT NOW(),
                `claimed`        TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (`user_id`, `achievement_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=latin1",
        ],
        '025_seasons_table' => [
            'check_sql' => "SHOW TABLES LIKE 'seasons'",
            'sql' => "CREATE TABLE `seasons` (
                `id`         INT AUTO_INCREMENT PRIMARY KEY,
                `name`       VARCHAR(64) NOT NULL,
                `start_time` DATETIME NOT NULL,
                `end_time`   DATETIME NOT NULL,
                `active`     TINYINT(1) NOT NULL DEFAULT 0,
                `reset_done` TINYINT(1) NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ],
        '026_season_scores_table' => [
            'check_sql' => "SHOW TABLES LIKE 'season_scores'",
            'sql' => "CREATE TABLE `season_scores` (
                `season_id` INT NOT NULL,
                `user_id`   INT UNSIGNED NOT NULL,
                `score`     INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`season_id`, `user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=latin1",
        ],
        '027_season_hall_of_fame_table' => [
            'check_sql' => "SHOW TABLES LIKE 'season_hall_of_fame'",
            'sql' => "CREATE TABLE `season_hall_of_fame` (
                `id`        INT AUTO_INCREMENT PRIMARY KEY,
                `season_id` INT NOT NULL,
                `user_id`   INT UNSIGNED NOT NULL,
                `rank_pos`  INT UNSIGNED NOT NULL,
                `score`     INT UNSIGNED NOT NULL,
                `username`  VARCHAR(64) NOT NULL,
                `title`     VARCHAR(64) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ],
        '028_alliance_wars_table' => [
            'check_sql' => "SHOW TABLES LIKE 'alliance_wars'",
            'sql' => "CREATE TABLE `alliance_wars` (
                `id`               INT AUTO_INCREMENT PRIMARY KEY,
                `challenger_id`    INT UNSIGNED NOT NULL,
                `defender_id`      INT UNSIGNED NOT NULL,
                `start_time`       DATETIME NOT NULL,
                `end_time`         DATETIME NOT NULL,
                `status`           TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `challenger_score` INT UNSIGNED NOT NULL DEFAULT 0,
                `defender_score`   INT UNSIGNED NOT NULL DEFAULT 0,
                `winner_id`        INT UNSIGNED NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=latin1",
        ],
        '029_alliance_war_scores_table' => [
            'check_sql' => "SHOW TABLES LIKE 'alliance_war_scores'",
            'sql' => "CREATE TABLE `alliance_war_scores` (
                `war_id`      INT NOT NULL,
                `user_id`     INT UNSIGNED NOT NULL,
                `alliance_id` INT UNSIGNED NOT NULL,
                `score`       INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`war_id`, `user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=latin1",
        ],
        '030_users_total_builds' => [
            'check_sql' => "SHOW COLUMNS FROM `users` LIKE 'total_builds'",
            'sql' => "ALTER TABLE `users`
                ADD COLUMN `total_builds`  INT UNSIGNED NOT NULL DEFAULT 0,
                ADD COLUMN `total_raids`   INT UNSIGNED NOT NULL DEFAULT 0,
                ADD COLUMN `total_trains`  INT UNSIGNED NOT NULL DEFAULT 0,
                ADD COLUMN `total_trades`  INT UNSIGNED NOT NULL DEFAULT 0",
        ],
        '031_premium_purchase_requests_table' => [
            'check_sql' => "SHOW TABLES LIKE 'premium_purchase_requests'",
            'sql' => "CREATE TABLE `premium_purchase_requests` (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=latin1",
        ],

        '032_default_season' => [
            'check_sql' => "SELECT id FROM seasons LIMIT 1",
            'sql'       => "INSERT INTO seasons (name, start_time, end_time, active)
                            VALUES ('Sezon 1', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), 1)",
        ],
    ];

    /**
     * @return array<int, string>
     */
    public function applyAll(\DB\SQL $db): array
    {
        $results = [];

        foreach (self::MIGRATIONS as $name => $migration) {
            try {
                if ($this->isApplied($db, $name, $migration['check_sql'])) {
                    $results[] = "[SKIP] {$name}";
                    continue;
                }

                $db->exec($migration['sql']);
                $results[] = "[ OK ] {$name}";
            } catch (\PDOException $e) {
                $results[] = "[FAIL] {$name}: {$e->getMessage()}";
            }
        }

        return $results;
    }

    private function isApplied(\DB\SQL $db, string $name, string $checkSql): bool
    {
        if ($name === '001_users_pass_hash_len') {
            $rows = $db->exec($checkSql);
            $len = (int) ($rows[0]['len'] ?? 0);
            return $len >= 256;
        }

        $rows = $db->exec($checkSql);
        return !empty($rows);
    }
}

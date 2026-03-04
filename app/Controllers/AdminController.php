<?php declare(strict_types=1);

namespace Devana\Controllers;

use Devana\Helpers\DataParser;
use Devana\Helpers\GravatarHelper;
use Devana\Helpers\InputSanitizer;
use Devana\Services\AchievementService;
use Devana\Services\AllianceWarService;
use Devana\Services\BarbarianService;
use Devana\Services\BotService;
use Devana\Services\FactionService;
use Devana\Services\MailerService;
use Devana\Services\MissionService;
use Devana\Services\PremiumStoreService;
use Devana\Services\SeasonService;
use Devana\Services\UserService;

final class AdminController extends Controller
{
    private function ensurePremiumSchema(): void
    {
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS `premium_packages` (
                `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `code`             VARCHAR(64)  NOT NULL,
                `name`             VARCHAR(128) NOT NULL,
                `credits`          INT UNSIGNED NOT NULL DEFAULT 0,
                `price_usd_cents`  INT UNSIGNED NOT NULL DEFAULT 0,
                `is_active`        TINYINT(1)   NOT NULL DEFAULT 1,
                `sort_order`       INT UNSIGNED NOT NULL DEFAULT 0,
                UNIQUE KEY `uniq_code` (`code`)
            ) ENGINE=InnoDB DEFAULT CHARSET=latin1"
        );
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS `premium_products` (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=latin1"
        );
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS `user_inventory` (
                `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `user_id`      INT UNSIGNED NOT NULL,
                `product_id`   INT UNSIGNED NOT NULL,
                `acquired_at`  DATETIME     NOT NULL,
                `acquire_type` VARCHAR(32)  NOT NULL DEFAULT 'purchase',
                `is_active`    TINYINT(1)   NOT NULL DEFAULT 0,
                UNIQUE KEY `uniq_user_product` (`user_id`, `product_id`),
                KEY `user_slot` (`user_id`, `is_active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=latin1"
        );
    }

    private function sanitizeCode(string $value): string
    {
        $clean = strtolower(trim($value));
        $clean = preg_replace('/[^a-z0-9_]+/', '_', $clean) ?? '';
        $clean = trim($clean, '_');
        if ($clean === '') {
            $clean = 'item_' . time();
        }
        return substr($clean, 0, 64);
    }

    private function sanitizeSlot(string $value): string
    {
        $allowed = ['profile_badge', 'profile_frame', 'profile_title', 'town_background'];
        if (in_array($value, $allowed, true)) {
            return $value;
        }
        return 'profile_badge';
    }

    private function backgroundProductCode(string $filename): string
    {
        $safe = strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', basename($filename)));
        $safe = trim($safe, '_');
        if ($safe === '') {
            $safe = 'back';
        }
        return substr('town_bg_' . $safe, 0, 64);
    }

    private function ensureMissionTemplateSchema(): void
    {
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS `mission_templates` (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=latin1"
        );

        $col = $this->db->exec("SHOW COLUMNS FROM `player_missions` LIKE 'template_id'");
        if (empty($col)) {
            $this->db->exec(
                "ALTER TABLE `player_missions`
                 ADD COLUMN `template_id` INT UNSIGNED NULL AFTER `type`,
                 ADD KEY `template_id` (`template_id`)"
            );
        }

        $count = (int) ($this->db->exec('SELECT COUNT(*) AS cnt FROM mission_templates')[0]['cnt'] ?? 0);
        if ($count === 0) {
            $defaults = [
                [MissionService::TYPE_BUILD, 'Tamamla {n} insaat', 'Complete {n} constructions', 1, 3, 100, 0, 200, 1],
                [MissionService::TYPE_TRAIN, 'Egit {n} birlik', 'Train {n} units', 5, 20, 100, 1, 200, 2],
                [MissionService::TYPE_RAID, 'Gonder {n} yagma', 'Send {n} raids', 1, 3, 150, 3, 200, 3],
                [MissionService::TYPE_TRADE, '{n} ticaret yap', 'Make {n} trades', 1, 3, 100, 2, 200, 4],
                [MissionService::TYPE_GOLD, '{n} altin topla', 'Collect {n} gold', 50, 200, 100, 4, 200, 5],
            ];

            foreach ($defaults as $d) {
                $this->db->exec(
                    'INSERT INTO mission_templates
                     (type, title_tr, title_en, target_min, target_max, reward_xp, reward_resource_index, reward_resource_amount, is_active, sort_order)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)',
                    $d
                );
            }
        }
    }

    private function getBackgroundDir(): string
    {
        $f3 = $this->f3;
        return $f3->get('ROOT') . '/default/1/';
    }

    private function listBackgrounds(): array
    {
        $dir = $this->getBackgroundDir();
        $patterns = ['back*.png', 'back*.jpg', 'back*.jpeg', 'back*.webp', 'back*.gif'];
        $files = [];
        foreach ($patterns as $pattern) {
            $matched = glob($dir . $pattern) ?: [];
            foreach ($matched as $m) {
                $files[] = $m;
            }
        }

        $names = array_values(array_unique(array_filter(array_map('basename', $files), static function (string $name): bool {
            return (bool) preg_match('/^back(\d*)\.(png|jpe?g|webp|gif)$/i', $name);
        })));
        sort($names);
        return $names;
    }

    private function getAssetDir(): string
    {
        return $this->f3->get('ROOT') . '/default/1/';
    }

    private function getConfigValue(string $name, string $default = ''): string
    {
        $row = $this->db->exec('SELECT value FROM config WHERE name = ? ORDER BY ord ASC LIMIT 1', [$name]);
        return (string) ($row[0]['value'] ?? $default);
    }

    private function setConfigValue(string $name, string $value): void
    {
        $existing = $this->db->exec('SELECT ord FROM config WHERE name = ? ORDER BY ord ASC LIMIT 1', [$name]);
        if (!empty($existing)) {
            $this->db->exec('UPDATE config SET value = ? WHERE ord = ?', [$value, (int) $existing[0]['ord']]);
            return;
        }

        $maxOrdRow = $this->db->exec('SELECT MAX(ord) AS mx FROM config');
        $nextOrd = (int) (($maxOrdRow[0]['mx'] ?? 0) + 1);
        $this->db->exec('INSERT INTO config (name, value, ord) VALUES (?, ?, ?)', [$name, $value, $nextOrd]);
    }

    private function backgroundMaskConfigKey(string $filename): string
    {
        $safe = strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', basename($filename)));
        $safe = trim($safe, '_');
        return 'town_background_mask_' . $safe;
    }

    /**
     * @param mixed $raw
     * @return array<int, array<string, int|string>>
     */
    private function normalizeBackgroundMaskZones($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $validFx = ['none', 'water', 'fire', 'wind', 'smoke', 'road'];
        $out = [];
        foreach ($raw as $zone) {
            if (!is_array($zone)) {
                continue;
            }
            $type  = (string) ($zone['t'] ?? 'rect');
            $rawFx = (string) ($zone['fx'] ?? 'none');
            $fx    = in_array($rawFx, $validFx, true) ? $rawFx : 'none';
            if ($type === 'rect' || !isset($zone['t'])) {
                $x = max(0, min(639, (int) ($zone['x'] ?? 0)));
                $y = max(0, min(371, (int) ($zone['y'] ?? 0)));
                $w = max(1, min(640 - $x, (int) ($zone['w'] ?? 0)));
                $h = max(1, min(372 - $y, (int) ($zone['h'] ?? 0)));
                if ($w < 4 || $h < 4) {
                    continue;
                }
                $out[] = ['t' => 'rect', 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'fx' => $fx];
                continue;
            }
            if ($type === 'circle' || $type === 'brush') {
                $cx = max(0, min(639, (int) ($zone['cx'] ?? $zone['x'] ?? 0)));
                $cy = max(0, min(371, (int) ($zone['cy'] ?? $zone['y'] ?? 0)));
                $r = max(2, min(120, (int) ($zone['r'] ?? 0)));
                $out[] = ['t' => $type, 'cx' => $cx, 'cy' => $cy, 'r' => $r, 'fx' => $fx];
                continue;
            }
            if ($type === 'poly' && isset($zone['points']) && is_array($zone['points'])) {
                $points = [];
                foreach ($zone['points'] as $pt) {
                    if (!is_array($pt)) {
                        continue;
                    }
                    $px = max(0, min(639, (int) ($pt['x'] ?? 0)));
                    $py = max(0, min(371, (int) ($pt['y'] ?? 0)));
                    $points[] = ['x' => $px, 'y' => $py];
                }
                if (count($points) >= 3) {
                    if (count($points) > 80) {
                        $points = array_slice($points, 0, 80);
                    }
                    $out[] = ['t' => 'poly', 'points' => $points, 'fx' => $fx];
                }
            }
        }
        if (count($out) > 2500) {
            $out = array_slice($out, 0, 2500);
        }
        return $out;
    }

    public function createAnnouncement(\Base $f3): void
    {
        if (!$this->requireAdmin()) return;
        if (!$this->requireCsrf('/admin')) return;

        $user = $this->currentUser();
        $title = trim(strip_tags($this->post('title', '')));
        $rawContent = $this->post('content', '');
        $content = trim(strip_tags($rawContent, '<p><br><strong><em><u><s><ol><ul><li><a><h1><h2><h3><blockquote><pre><code><img><span><sub><sup>'));

        if (empty($title) || empty(strip_tags($content))) {
            $this->flashAndRedirect('Please fill in all fields.', '/admin');
            return;
        }

        $this->db->exec(
            'INSERT INTO announcements (author, title, content) VALUES (?, ?, ?)',
            [(int) $user['id'], $title, $content]
        );

        $this->flashAndRedirect('Announcement created.', '/admin');
    }

    public function deleteAnnouncement(\Base $f3): void
    {
        if (!$this->requireAdmin()) return;
        if (!$this->requireCsrf('/admin')) return;

        $id = InputSanitizer::cleanInt($this->post('announcement_id', 0));
        if ($id > 0) {
            $this->db->exec('DELETE FROM announcements WHERE id = ?', [$id]);
        }

        $this->flashAndRedirect('Announcement deleted.', '/admin');
    }

    private function ensureAchievementsSchema(): void
    {
        $achievementService = new AchievementService($this->db);
        $achievementService->ensureDefaults();
    }

    public function panel(\Base $f3): void
    {
        if (!$this->requireAdmin()) return;
        $this->ensureMissionTemplateSchema();
        $this->ensurePremiumSchema();
        try { $this->ensureAchievementsSchema(); } catch (\Throwable $e) { /* non-critical */ }

        $configRows = $this->db->exec('SELECT * FROM config ORDER BY ord ASC');

        // Map config rows to named values
        $configMap = [];
        foreach ($configRows as $row) {
            $configMap[$row['name'] ?? 'config_' . $row['ord']] = $row['value'] ?? '';
        }

        // Ensure template-expected keys exist with defaults
        $defaults = [
            'game_name' => $this->f3->get('game.title') ?? 'Devana',
            'game_speed' => '1',
            'map_size' => '200',
            'max_towns' => '3',
            'registration_open' => $configMap['register'] ?? '1',
            'beginner_protection' => '72',
            'recaptcha_enabled' => '0',
            'recaptcha_site_key' => '',
            'recaptcha_secret_key' => '',
            'site_logo_path' => '/default/1/logo.jpg',
            'site_favicon_path' => '/default/1/logo.jpg',
            'meta_author' => 'Devana',
            'meta_description' => $this->f3->get('game.title') ?? 'Devana',
            'meta_keywords' => 'devana,strategy,game',
            'default_lang' => 'en.php',
            'mail_enabled' => '0',
            'smtp_host' => '',
            'smtp_port' => '587',
            'smtp_auth' => '1',
            'smtp_username' => '',
            'smtp_password' => '',
            'smtp_secure' => 'tls',
            'smtp_from_email' => '',
            'smtp_from_name' => 'Devana',
            'login_max_attempts' => '5',
            'login_lock_minutes' => '15',
            'timezone' => 'UTC',
        ];
        foreach ($defaults as $key => $default) {
            if (!isset($configMap[$key])) {
                $configMap[$key] = $default;
            }
        }

        $userCount = (int) ($this->db->exec('SELECT COUNT(*) AS cnt FROM users WHERE level > 0')[0]['cnt'] ?? 0);
        $townCount = (int) ($this->db->exec('SELECT COUNT(*) AS cnt FROM towns')[0]['cnt'] ?? 0);
        $allianceCount = (int) ($this->db->exec('SELECT COUNT(*) AS cnt FROM alliances')[0]['cnt'] ?? 0);

        $users = $this->db->exec('SELECT id, name, level, points, joined AS registered_date FROM users ORDER BY id LIMIT 50');

        $announcements = $this->db->exec(
            'SELECT a.id, a.title, a.content, a.created_at, u.name AS author_name
             FROM announcements a
             LEFT JOIN users u ON a.author = u.id
             ORDER BY a.created_at DESC'
        );

        $barbarianService = new BarbarianService($this->db);
        $camps = $barbarianService->getAllCamps();

        $botService = new BotService($this->db);
        $bots       = $botService->getBots();
        $factionService = new FactionService($this->db);
        $factions = $factionService->getAllWithStats();
        $botConfig  = [
            'bot_active'      => (int) ($configMap['bot_active']      ?? 0),
            'bot_count'       => (int) ($configMap['bot_count']       ?? 0),
            'bot_aggression'  => (int) ($configMap['bot_aggression']  ?? 2),
        ];
        $lang = (array) $this->f3->get('lang');
        $typeLabels = [
            MissionService::TYPE_BUILD => $lang['missionBuild'] ?? 'Complete {n} constructions',
            MissionService::TYPE_TRAIN => $lang['missionTrain'] ?? 'Train {n} units',
            MissionService::TYPE_RAID  => $lang['missionRaid']  ?? 'Send {n} raids',
            MissionService::TYPE_TRADE => $lang['missionTrade'] ?? 'Make {n} trades',
            MissionService::TYPE_GOLD  => $lang['missionGold']  ?? 'Collect {n} gold',
        ];
        $missionTemplates = [];
        $templateTableExists = !empty($this->db->exec("SHOW TABLES LIKE 'mission_templates'"));
        if ($templateTableExists) {
            $missionTemplates = $this->db->exec(
                'SELECT id, type, title_tr, title_en, target_min, target_max, reward_xp,
                        reward_resource_index, reward_resource_amount, is_active, sort_order
                 FROM mission_templates
                 ORDER BY sort_order ASC, id ASC'
            ) ?: [];
            foreach ($missionTemplates as &$tpl) {
                $tpl['type_label'] = $typeLabels[(int) ($tpl['type'] ?? -1)] ?? ('Type ' . (int) ($tpl['type'] ?? 0));
            }
            unset($tpl);
        }

        $rootPath = (string) $this->f3->get('ROOT');
        $cronScript = $rootPath . '/bin/cron.php';
        $cronLog = $rootPath . '/tmp/logs/cron.log';
        $cronCommand = 'cd ' . escapeshellarg($rootPath) . ' && php bin/cron.php >> ' . escapeshellarg($cronLog) . ' 2>&1';
        $phpMailerAvailable = (new MailerService($this->db))->isPhpMailerAvailable();
        $premiumService = new PremiumStoreService($this->db);
        $premiumService->bootstrapCatalog();
        $premiumPackages = $this->db->exec(
            'SELECT id, code, name, credits, price_usd_cents, is_active, sort_order
             FROM premium_packages
             ORDER BY sort_order ASC, id ASC'
        );
        $premiumProducts = $this->db->exec(
            'SELECT p.id, p.code, p.name, p.description, p.slot, p.rarity, p.price_credits, p.icon, p.is_active, p.sort_order,
                    (SELECT COUNT(*) FROM user_inventory i WHERE i.product_id = p.id) AS owned_count
             FROM premium_products p
             ORDER BY p.sort_order ASC, p.id ASC'
        );
        $premiumInventoryRows = $this->db->exec(
            'SELECT i.id, i.user_id, u.name AS user_name, i.product_id, p.name AS product_name, p.slot, p.rarity,
                    i.acquire_type, i.is_active, i.acquired_at
             FROM user_inventory i
             LEFT JOIN users u ON u.id = i.user_id
             LEFT JOIN premium_products p ON p.id = i.product_id
             ORDER BY i.id DESC
             LIMIT 100'
        );
        $backgroundPremiumRows = $this->db->exec(
            'SELECT id, icon, price_credits, is_active
             FROM premium_products
             WHERE slot = ?
             ORDER BY id ASC',
            ['town_background']
        );
        $backgroundPremiumMap = [];
        foreach ($backgroundPremiumRows as $row) {
            $filename = basename((string) ($row['icon'] ?? ''));
            if ($filename === '') {
                continue;
            }
            $backgroundPremiumMap[$filename] = [
                'product_id' => (int) ($row['id'] ?? 0),
                'price_credits' => max(0, (int) ($row['price_credits'] ?? 0)),
                'is_active' => (int) ($row['is_active'] ?? 0),
            ];
        }
        if ($this->hasUsersPremiumBalanceColumn()) {
            $premiumUsers = $this->db->exec(
                'SELECT id, name, premium_balance
                 FROM users
                 WHERE level > 0
                 ORDER BY id DESC
                 LIMIT 200'
            );
        } else {
            $premiumUsers = $this->db->exec(
                'SELECT id, name, 0 AS premium_balance
                 FROM users
                 WHERE level > 0
                 ORDER BY id DESC
                 LIMIT 200'
            );
        }

        $this->render('admin/panel.html', [
            'page_title' => 'Admin Panel',
            'config' => $configMap,
            'admin_users' => $users,
            'admin_search' => '',
            'server_info' => [
                'total_players' => $userCount,
                'total_towns' => $townCount,
                'total_alliances' => $allianceCount,
                'server_time' => date('Y-m-d H:i:s'),
                'uptime' => '',
            ],
            'backgrounds' => $this->listBackgrounds(),
            'admin_announcements' => $announcements,
            'cron_script_path' => $cronScript,
            'cron_log_path' => $cronLog,
            'cron_command' => $cronCommand,
            'admin_camps' => $camps,
            'admin_bots' => $bots,
            'admin_factions' => $factions,
            'bot_config' => $botConfig,
            'bot_cron_script_path' => $cronScript,
            'bot_cron_log_path' => $cronLog,
            'bot_cron_command' => $cronCommand,
            'admin_mission_templates' => $missionTemplates,
            'admin_mission_type_options' => $typeLabels,
            'mission_templates_enabled' => $templateTableExists,
            'phpmailer_available' => $phpMailerAvailable,
            'admin_premium_packages' => $premiumPackages,
            'admin_premium_products' => $premiumProducts,
            'admin_premium_inventory' => $premiumInventoryRows,
            'admin_premium_users' => $premiumUsers,
            'background_premium_map' => $backgroundPremiumMap,
            'admin_achievements' => !empty($this->db->exec("SHOW TABLES LIKE 'achievements'")) ? (new AchievementService($this->db))->getAllAchievements() : [],
            'admin_seasons' => !empty($this->db->exec("SHOW TABLES LIKE 'seasons'")) ? (new SeasonService($this->db))->getAllSeasons() : [],
            'admin_active_season' => !empty($this->db->exec("SHOW TABLES LIKE 'seasons'")) ? (new SeasonService($this->db))->getActiveSeason() : null,
            'admin_wars' => !empty($this->db->exec("SHOW TABLES LIKE 'alliance_wars'")) ? (new AllianceWarService($this->db))->getAllWars() : [],
        ]);
    }

    public function updateBackgroundPremium(\Base $f3): void
    {
        if (!$this->requireAdmin('/towns')) return;
        if (!$this->requireCsrf('/admin')) return;
        $this->ensurePremiumSchema();

        $filename = basename((string) $this->post('filename', ''));
        $available = $this->listBackgrounds();
        if (!in_array($filename, $available, true)) {
            $this->flashAndRedirect('Invalid filename.', '/admin#maintenance');
            return;
        }

        $isPremium = InputSanitizer::cleanInt($this->post('is_premium', 0)) ? 1 : 0;
        $price = max(1, InputSanitizer::cleanInt($this->post('price_credits', 100)));
        $code = $this->backgroundProductCode($filename);
        $name = 'Town Background: ' . $filename;
        $desc = 'Unlocks town background ' . $filename;
        $sort = 900 + max(0, InputSanitizer::cleanInt($this->post('sort_order', 0)));

        $existing = $this->db->exec(
            'SELECT id FROM premium_products WHERE slot = ? AND icon = ? LIMIT 1',
            ['town_background', $filename]
        );

        if ($isPremium === 1) {
            if (!empty($existing)) {
                $this->db->exec(
                    'UPDATE premium_products
                     SET code = ?, name = ?, description = ?, slot = ?, rarity = ?, price_credits = ?, icon = ?, is_active = 1, sort_order = ?
                     WHERE id = ? LIMIT 1',
                    [$code, $name, $desc, 'town_background', 'rare', $price, $filename, $sort, (int) $existing[0]['id']]
                );
            } else {
                $this->db->exec(
                    'INSERT INTO premium_products (code, name, description, slot, rarity, price_credits, icon, is_active, sort_order)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)',
                    [$code, $name, $desc, 'town_background', 'rare', $price, $filename, $sort]
                );
            }
            $this->flashAndRedirect('Background marked as premium.', '/admin#maintenance');
            return;
        }

        if (!empty($existing)) {
            $this->db->exec(
                'UPDATE premium_products SET is_active = 0 WHERE id = ? LIMIT 1',
                [(int) $existing[0]['id']]
            );
        }

        $this->flashAndRedirect('Background set as free.', '/admin#maintenance');
    }

    public function premiumPackageCreate(\Base $f3): void
    {
        if (!$this->requireAdmin()) return;
        if (!$this->requireCsrf('/admin')) return;
        $this->ensurePremiumSchema();

        $code = $this->sanitizeCode((string) $this->post('code', ''));
        $name = trim((string) $this->post('name', ''));
        $credits = max(0, InputSanitizer::cleanInt($this->post('credits', 0)));
        $priceUsdCents = max(0, InputSanitizer::cleanInt($this->post('price_usd_cents', 0)));
        $sortOrder = max(0, InputSanitizer::cleanInt($this->post('sort_order', 0)));
        $isActive = InputSanitizer::cleanInt($this->post('is_active', 1)) ? 1 : 0;

        if ($name === '' || $credits <= 0) {
            $this->flashAndRedirect('Package name and credits are required.', '/admin#premium');
            return;
        }

        try {
            $this->db->exec(
                'INSERT INTO premium_packages (code, name, credits, price_usd_cents, is_active, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$code, $name, $credits, $priceUsdCents, $isActive, $sortOrder]
            );
            $this->flashAndRedirect('Premium package created.', '/admin#premium');
            return;
        } catch (\Throwable $e) {
            $this->flashAndRedirect('Premium package could not be created (duplicate code?).', '/admin#premium');
            return;
        }
    }

    public function premiumPackageUpdate(\Base $f3): void
    {
        if (!$this->requireAdmin()) return;
        if (!$this->requireCsrf('/admin')) return;
        $this->ensurePremiumSchema();

        $id = max(0, InputSanitizer::cleanInt($this->post('id', 0)));
        if ($id <= 0) {
            $this->flashAndRedirect('Invalid package.', '/admin#premium');
            return;
        }

        $code = $this->sanitizeCode((string) $this->post('code', ''));
        $name = trim((string) $this->post('name', ''));
        $credits = max(0, InputSanitizer::cleanInt($this->post('credits', 0)));
        $priceUsdCents = max(0, InputSanitizer::cleanInt($this->post('price_usd_cents', 0)));
        $sortOrder = max(0, InputSanitizer::cleanInt($this->post('sort_order', 0)));
        $isActive = InputSanitizer::cleanInt($this->post('is_active', 1)) ? 1 : 0;

        if ($name === '' || $credits <= 0) {
            $this->flashAndRedirect('Package name and credits are required.', '/admin#premium');
            return;
        }

        try {
            $this->db->exec(
                'UPDATE premium_packages
                 SET code = ?, name = ?, credits = ?, price_usd_cents = ?, is_active = ?, sort_order = ?
                 WHERE id = ? LIMIT 1',
                [$code, $name, $credits, $priceUsdCents, $isActive, $sortOrder, $id]
            );
            $this->flashAndRedirect("Premium package #{$id} updated.", '/admin#premium');
            return;
        } catch (\Throwable $e) {
            $this->flashAndRedirect('Premium package could not be updated (duplicate code?).', '/admin#premium');
            return;
        }
    }

    public function premiumPackageDelete(\Base $f3): void
    {
        if (!$this->requireAdmin()) return;
        if (!$this->requireCsrf('/admin')) return;
        $this->ensurePremiumSchema();

        $id = max(0, InputSanitizer::cleanInt($this->post('id', 0)));
        if ($id <= 0) {
            $this->flashAndRedirect('Invalid package.', '/admin#premium');
            return;
        }

        $this->db->exec('DELETE FROM premium_packages WHERE id = ? LIMIT 1', [$id]);
        $this->flashAndRedirect("Premium package #{$id} deleted.", '/admin#premium');
    }

    public function premiumProductCreate(\Base $f3): void
    {
        if (!$this->requireAdmin()) return;
        if (!$this->requireCsrf('/admin')) return;
        $this->ensurePremiumSchema();

        $code = $this->sanitizeCode((string) $this->post('code', ''));
        $name = trim((string) $this->post('name', ''));
        $description = trim((string) $this->post('description', ''));
        $slot = $this->sanitizeSlot((string) $this->post('slot', 'profile_badge'));
        $rarity = $this->sanitizeCode((string) $this->post('rarity', 'common'));
        $priceCredits = max(0, InputSanitizer::cleanInt($this->post('price_credits', 0)));
        $icon = $this->sanitizeCode((string) $this->post('icon', 'badge'));
        $sortOrder = max(0, InputSanitizer::cleanInt($this->post('sort_order', 0)));
        $isActive = InputSanitizer::cleanInt($this->post('is_active', 1)) ? 1 : 0;

        if ($name === '' || $priceCredits < 0) {
            $this->flashAndRedirect('Product name is required.', '/admin#premium');
            return;
        }

        try {
            $this->db->exec(
                'INSERT INTO premium_products (code, name, description, slot, rarity, price_credits, icon, is_active, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$code, $name, $description, $slot, $rarity, $priceCredits, $icon, $isActive, $sortOrder]
            );
            $this->flashAndRedirect('Premium product created.', '/admin#premium');
            return;
        } catch (\Throwable $e) {
            $this->flashAndRedirect('Premium product could not be created (duplicate code?).', '/admin#premium');
            return;
        }
    }

    public function premiumProductUpdate(\Base $f3): void
    {
        if (!$this->requireAdmin()) return;
        if (!$this->requireCsrf('/admin')) return;
        $this->ensurePremiumSchema();

        $id = max(0, InputSanitizer::cleanInt($this->post('id', 0)));
        if ($id <= 0) {
            $this->flashAndRedirect('Invalid product.', '/admin#premium');
            return;
        }

        $code = $this->sanitizeCode((string) $this->post('code', ''));
        $name = trim((string) $this->post('name', ''));
        $description = trim((string) $this->post('description', ''));
        $slot = $this->sanitizeSlot((string) $this->post('slot', 'profile_badge'));
        $rarity = $this->sanitizeCode((string) $this->post('rarity', 'common'));
        $priceCredits = max(0, InputSanitizer::cleanInt($this->post('price_credits', 0)));
        $icon = $this->sanitizeCode((string) $this->post('icon', 'badge'));
        $sortOrder = max(0, InputSanitizer::cleanInt($this->post('sort_order', 0)));
        $isActive = InputSanitizer::cleanInt($this->post('is_active', 1)) ? 1 : 0;

        if ($name === '') {
            $this->flashAndRedirect('Product name is required.', '/admin#premium');
            return;
        }

        try {
            $this->db->exec(
                'UPDATE premium_products
                 SET code = ?, name = ?, description = ?, slot = ?, rarity = ?, price_credits = ?, icon = ?, is_active = ?, sort_order = ?
                 WHERE id = ? LIMIT 1',
                [$code, $name, $description, $slot, $rarity, $priceCredits, $icon, $isActive, $sortOrder, $id]
            );
            $this->flashAndRedirect("Premium product #{$id} updated.", '/admin#premium');
            return;
        } catch (\Throwable $e) {
            $this->flashAndRedirect('Premium product could not be updated (duplicate code?).', '/admin#premium');
            return;
        }
    }

    public function premiumProductDelete(\Base $f3): void
    {
        if (!$this->requireAdmin()) return;
        if (!$this->requireCsrf('/admin')) return;
        $this->ensurePremiumSchema();

        $id = max(0, InputSanitizer::cleanInt($this->post('id', 0)));
        if ($id <= 0) {
            $this->flashAndRedirect('Invalid product.', '/admin#premium');
            return;
        }

        $this->db->exec('DELETE FROM user_inventory WHERE product_id = ?', [$id]);
        $this->db->exec('DELETE FROM premium_products WHERE id = ? LIMIT 1', [$id]);
        $this->flashAndRedirect("Premium product #{$id} deleted.", '/admin#premium');
    }

    public function premiumInventoryGrant(\Base $f3): void
    {
        if (!$this->requireAdmin()) return;
        if (!$this->requireCsrf('/admin')) return;
        $this->ensurePremiumSchema();

        $userId = max(0, InputSanitizer::cleanInt($this->post('user_id', 0)));
        $productId = max(0, InputSanitizer::cleanInt($this->post('product_id', 0)));
        $activate = InputSanitizer::cleanInt($this->post('activate_now', 0)) ? 1 : 0;

        if ($userId <= 0 || $productId <= 0) {
            $this->flashAndRedirect('User and product are required.', '/admin#premium');
            return;
        }

        $exists = $this->db->exec(
            'SELECT id FROM user_inventory WHERE user_id = ? AND product_id = ? LIMIT 1',
            [$userId, $productId]
        );
        if (empty($exists)) {
            $this->db->exec(
                "INSERT INTO user_inventory (user_id, product_id, acquired_at, acquire_type, is_active)
                 VALUES (?, ?, NOW(), 'admin_grant', ?)",
                [$userId, $productId, $activate]
            );
        } else {
            $this->db->exec(
                'UPDATE user_inventory SET is_active = ? WHERE user_id = ? AND product_id = ?',
                [$activate, $userId, $productId]
            );
        }

        if ($activate === 1) {
            $slotRows = $this->db->exec('SELECT slot FROM premium_products WHERE id = ? LIMIT 1', [$productId]);
            $slot = (string) ($slotRows[0]['slot'] ?? '');
            if ($slot !== '') {
                $this->db->exec(
                    'UPDATE user_inventory i
                     INNER JOIN premium_products p ON p.id = i.product_id
                     SET i.is_active = 0
                     WHERE i.user_id = ? AND p.slot = ? AND i.product_id <> ?',
                    [$userId, $slot, $productId]
                );
            }
        }

        $this->flashAndRedirect('Inventory item granted.', '/admin#premium');
    }

    public function premiumInventoryRevoke(\Base $f3): void
    {
        if (!$this->requireAdmin()) return;
        if (!$this->requireCsrf('/admin')) return;
        $this->ensurePremiumSchema();

        $inventoryId = max(0, InputSanitizer::cleanInt($this->post('inventory_id', 0)));
        if ($inventoryId <= 0) {
            $this->flashAndRedirect('Invalid inventory item.', '/admin#premium');
            return;
        }

        $this->db->exec('DELETE FROM user_inventory WHERE id = ? LIMIT 1', [$inventoryId]);
        $this->flashAndRedirect("Inventory item #{$inventoryId} removed.", '/admin#premium');
    }

    public function premiumBalanceUpdate(\Base $f3): void
    {
        if (!$this->requireAdmin()) return;
        if (!$this->requireCsrf('/admin')) return;
        $this->ensureUsersPremiumBalanceColumn();

        $userId = max(0, InputSanitizer::cleanInt($this->post('user_id', 0)));
        $balance = max(0, InputSanitizer::cleanInt($this->post('premium_balance', 0)));
        if ($userId <= 0) {
            $this->flashAndRedirect('Invalid user.', '/admin#premium');
            return;
        }

        if (!$this->hasUsersPremiumBalanceColumn()) {
            $this->flashAndRedirect('premium_balance column is missing and could not be created automatically.', '/admin#premium');
            return;
        }

        $this->db->exec('UPDATE users SET premium_balance = ? WHERE id = ? LIMIT 1', [$balance, $userId]);
        $this->flashAndRedirect("User #{$userId} premium balance updated.", '/admin#premium');
    }

    public function updateConfig(\Base $f3): void
    {
        if (!$this->requireAdmin()) return;
        if (!$this->requireCsrf('/admin')) return;

        $updates = [
            'game_name' => trim((string) $this->post('game_name', '')),
            'game_speed' => (string) max(1, InputSanitizer::cleanInt($this->post('game_speed', 1))),
            'map_size' => (string) max(50, InputSanitizer::cleanInt($this->post('map_size', 200))),
            'max_towns' => (string) max(1, InputSanitizer::cleanInt($this->post('max_towns', 3))),
            'beginner_protection' => (string) max(0, InputSanitizer::cleanInt($this->post('beginner_protection', 72))),
            'register' => (string) (InputSanitizer::cleanInt($this->post('registration_open', 1)) ? 1 : 0),
            'recaptcha_enabled' => (string) (InputSanitizer::cleanInt($this->post('recaptcha_enabled', 0)) ? 1 : 0),
            'recaptcha_site_key' => trim((string) $this->post('recaptcha_site_key', '')),
            'recaptcha_secret_key' => trim((string) $this->post('recaptcha_secret_key', '')),
            'meta_author' => trim((string) $this->post('meta_author', '')),
            'meta_description' => trim((string) $this->post('meta_description', '')),
            'meta_keywords' => trim((string) $this->post('meta_keywords', '')),
            'default_lang' => trim((string) $this->post('default_lang', 'en.php')),
            'mail_enabled' => (string) (InputSanitizer::cleanInt($this->post('mail_enabled', 0)) ? 1 : 0),
            'smtp_host' => trim((string) $this->post('smtp_host', '')),
            'smtp_port' => (string) max(1, InputSanitizer::cleanInt($this->post('smtp_port', 587))),
            'smtp_auth' => (string) (InputSanitizer::cleanInt($this->post('smtp_auth', 1)) ? 1 : 0),
            'smtp_username' => trim((string) $this->post('smtp_username', '')),
            'smtp_password' => trim((string) $this->post('smtp_password', '')),
            'smtp_secure' => trim((string) $this->post('smtp_secure', 'tls')),
            'smtp_from_email' => trim((string) $this->post('smtp_from_email', '')),
            'smtp_from_name' => trim((string) $this->post('smtp_from_name', 'Devana')),
            'login_max_attempts' => (string) max(1, InputSanitizer::cleanInt($this->post('login_max_attempts', 5))),
            'login_lock_minutes' => (string) max(1, InputSanitizer::cleanInt($this->post('login_lock_minutes', 15))),
            'timezone' => trim((string) $this->post('timezone', 'UTC')),
        ];

        if ($updates['game_name'] === '') {
            unset($updates['game_name']);
        }
        if (!preg_match('/^[a-z]{2}\.php$/', $updates['default_lang'])) {
            $updates['default_lang'] = 'en.php';
        }
        if (!in_array($updates['smtp_secure'], ['tls', 'ssl', 'none'], true)) {
            $updates['smtp_secure'] = 'tls';
        }
        if (@timezone_open($updates['timezone']) === false) {
            $updates['timezone'] = 'UTC';
        }
        if ($updates['smtp_password'] === '') {
            unset($updates['smtp_password']);
        }

        foreach ($updates as $name => $value) {
            $existing = $this->db->exec('SELECT ord FROM config WHERE name = ? LIMIT 1', [$name]);
            if (!empty($existing)) {
                $this->db->exec('UPDATE config SET value = ? WHERE name = ?', [$value, $name]);
                continue;
            }

            $nextOrd = (int) (($this->db->exec('SELECT COALESCE(MAX(ord), -1) + 1 AS next_ord FROM config')[0]['next_ord'] ?? 0));
            $this->db->exec(
                'INSERT INTO config (name, value, ord) VALUES (?, ?, ?)',
                [$name, $value, $nextOrd]
            );
        }

        $this->flashAndRedirect('Configuration updated.', '/admin');
    }

    public function sendAll(\Base $f3): void
    {
        if (!$this->requireAdmin()) return;
        if (!$this->requireCsrf('/admin')) return;

        $user = $this->currentUser();
        $subject = InputSanitizer::clean($this->post('subject', ''));
        $contents = InputSanitizer::clean($this->post('contents', ''));

        if (empty($subject) || empty($contents)) {
            $this->flashAndRedirect('Please fill in all fields.', '/admin');
            return;
        }

        $users = $this->db->exec('SELECT id FROM users WHERE level > 0');

        foreach ($users as $u) {
            $this->db->exec(
                'INSERT INTO messages (sender, recipient, subject, contents, sent) VALUES (?, ?, ?, ?, NOW())',
                [$user['id'], (int) $u['id'], $subject, $contents]
            );
        }

        $this->flashAndRedirect('Message sent to all users.', '/admin');
    }

    public function deleteUser(\Base $f3): void
    {
        if (!$this->requireOwner()) return;
        if (!$this->requireCsrf('/admin')) return;

        $user = $this->currentUser();
        $userId = InputSanitizer::cleanInt($this->post('user_id', 0));

        if ($userId === $user['id']) {
            $this->flashAndRedirect('Cannot delete yourself.', '/admin');
            return;
        }

        $this->db->exec('UPDATE users SET level = 0 WHERE id = ?', [$userId]);
        $this->flashAndRedirect('User disabled.', '/admin');
    }

    public function setLevel(\Base $f3): void
    {
        if (!$this->requireOwner()) return;
        if (!$this->requireCsrf('/admin')) return;

        $userId = InputSanitizer::cleanInt($this->post('user_id', 0));
        $level = InputSanitizer::cleanInt($this->post('level', 1));

        $this->db->exec('UPDATE users SET level = ? WHERE id = ?', [$level, $userId]);
        $this->flashAndRedirect($f3->get('lang.userLevelUpdated') ?? 'User level updated.', '/admin');
    }

    public function setPoints(\Base $f3): void
    {
        if (!$this->requireAdmin()) return;
        if (!$this->requireCsrf('/admin')) return;

        $userId = InputSanitizer::cleanInt($this->post('user_id', 0));
        $points = InputSanitizer::cleanInt($this->post('points', 0));

        $this->db->exec('UPDATE users SET points = ? WHERE id = ?', [$points, $userId]);
        $this->flashAndRedirect('User points updated.', '/admin');
    }

    public function clean(\Base $f3): void
    {
        if (!$this->requireOwner()) return;
        if (!$this->requireCsrf('/admin')) return;

        // Clean up expired delete queue
        $expired = $this->db->exec('SELECT user FROM d_queue WHERE dueTime <= NOW()');

        foreach ($expired as $row) {
            $uid = (int) $row['user'];
            $this->db->exec('DELETE FROM towns WHERE owner = ?', [$uid]);
            $this->db->exec('DELETE FROM messages WHERE sender = ? OR recipient = ?', [$uid, $uid]);
            $this->db->exec('DELETE FROM reports WHERE recipient = ?', [$uid]);
            $this->db->exec('DELETE FROM users WHERE id = ?', [$uid]);
            $this->db->exec('DELETE FROM d_queue WHERE user = ?', [$uid]);
        }

        $this->flashAndRedirect('Cleanup completed. Removed ' . count($expired) . ' accounts.', '/admin');
    }

    public function recalcPoints(\Base $f3): void
    {
        if (!$this->requireAdmin()) return;
        if (!$this->requireCsrf('/admin')) return;

        $users = $this->db->exec('SELECT id FROM users WHERE level > 0');
        $userService = new UserService($this->db);
        foreach ($users as $u) {
            $userService->recalculatePoints((int) $u['id']);
        }

        $this->flashAndRedirect('Points recalculated for ' . count($users) . ' users.', '/admin');
    }

    public function cleanForums(\Base $f3): void
    {
        if (!$this->requireAdmin()) return;
        if (!$this->requireCsrf('/admin')) return;

        // Delete threads with no posts
        $emptyThreads = $this->db->exec(
            'SELECT t.id FROM forum_threads t LEFT JOIN forum_posts p ON p.thread_id = t.id WHERE p.id IS NULL'
        );
        $count = 0;
        foreach ($emptyThreads as $t) {
            $this->db->exec('DELETE FROM forum_threads WHERE id = ?', [(int) $t['id']]);
            $count++;
        }

        $this->flashAndRedirect('Cleaned ' . $count . ' empty forum threads.', '/admin');
    }

    public function cleanInactive(\Base $f3): void
    {
        if (!$this->requireAdmin()) return;
        if (!$this->requireCsrf('/admin')) return;

        $days = max(1, InputSanitizer::cleanInt($this->post('inactive_days', 30)));

        // Disable users who haven't logged in for X days (except admins)
        $result = $this->db->exec(
            'UPDATE users SET level = 0 WHERE level > 0 AND level < 4 AND lastVisit < DATE_SUB(NOW(), INTERVAL ? DAY)',
            [$days]
        );

        // Abandon their towns
        $this->db->exec(
            'UPDATE towns SET owner = 0 WHERE owner IN (SELECT id FROM users WHERE level = 0)'
        );

        $this->flashAndRedirect('Inactive players (>' . $days . ' days) have been disabled.', '/admin');
    }

    public function purgeReports(\Base $f3): void
    {
        if (!$this->requireAdmin()) return;
        if (!$this->requireCsrf('/admin')) return;

        $days = max(1, InputSanitizer::cleanInt($this->post('report_days', 7)));

        $this->db->exec(
            'DELETE FROM reports WHERE sent < DATE_SUB(NOW(), INTERVAL ? DAY)',
            [$days]
        );

        $this->flashAndRedirect('Reports older than ' . $days . ' days have been purged.', '/admin');
    }

    public function uploadBackground(\Base $f3): void
    {
        if (!$this->requireAdmin('/towns')) return;
        if (!$this->requireCsrf('/admin')) return;

        $file = $f3->get('FILES.background');
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            $this->flashAndRedirect('No file uploaded.', '/admin');
            return;
        }

        // Validate image type
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        $allowedMimes = [
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
        ];
        if (!isset($allowedMimes[$mime])) {
            $this->flashAndRedirect('Only PNG, GIF, JPG and WEBP files are allowed.', '/admin');
            return;
        }
        $ext = $allowedMimes[$mime];

        // Generate filename: back.ext, back2.ext, back3.ext, ...
        $dir = $this->getBackgroundDir();
        $existing = $this->listBackgrounds();
        $maxNum = 0;
        foreach ($existing as $bg) {
            if (preg_match('/^back(\d*)\.[a-z0-9]+$/i', $bg, $m)) {
                $num = $m[1] === '' ? 1 : (int) $m[1];
                if ($num > $maxNum) {
                    $maxNum = $num;
                }
            }
        }
        $nextNum = $maxNum + 1;
        $filename = 'back' . ($nextNum === 1 ? '' : $nextNum) . '.' . $ext;

        if (!move_uploaded_file($file['tmp_name'], $dir . $filename)) {
            $this->flashAndRedirect('Failed to save file.', '/admin');
            return;
        }

        $this->flashAndRedirect('Background "' . $filename . '" uploaded.', '/admin');
    }

    public function uploadSiteLogo(\Base $f3): void
    {
        if (!$this->requireAdmin('/towns')) return;
        if (!$this->requireCsrf('/admin')) return;

        $file = $f3->get('FILES.site_logo');
        if (!$file || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->flashAndRedirect('No logo file uploaded.', '/admin#config');
            return;
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($file['tmp_name']);
        $allowed = [
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        ];
        if (!isset($allowed[$mime])) {
            $this->flashAndRedirect('Logo must be PNG/JPG/WEBP/GIF.', '/admin#config');
            return;
        }

        $ext = $allowed[$mime];
        $filename = 'site-logo.' . $ext;
        $target = $this->getAssetDir() . $filename;

        if (!move_uploaded_file($file['tmp_name'], $target)) {
            $this->flashAndRedirect('Failed to save logo file.', '/admin#config');
            return;
        }

        $this->setConfigValue('site_logo_path', '/default/1/' . $filename);
        $this->flashAndRedirect('Site logo updated.', '/admin#config');
    }

    public function uploadSiteFavicon(\Base $f3): void
    {
        if (!$this->requireAdmin('/towns')) return;
        if (!$this->requireCsrf('/admin')) return;

        $file = $f3->get('FILES.site_favicon');
        if (!$file || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->flashAndRedirect('No favicon file uploaded.', '/admin#config');
            return;
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($file['tmp_name']);
        $allowed = [
            'image/x-icon' => 'ico',
            'image/vnd.microsoft.icon' => 'ico',
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        ];
        if (!isset($allowed[$mime])) {
            $this->flashAndRedirect('Favicon must be ICO/PNG/JPG/WEBP/GIF.', '/admin#config');
            return;
        }

        $ext = $allowed[$mime];
        $filename = 'site-favicon.' . $ext;
        $target = $this->getAssetDir() . $filename;

        if (!move_uploaded_file($file['tmp_name'], $target)) {
            $this->flashAndRedirect('Failed to save favicon file.', '/admin#config');
            return;
        }

        $this->setConfigValue('site_favicon_path', '/default/1/' . $filename);
        $this->flashAndRedirect('Site favicon updated.', '/admin#config');
    }

    public function userDetail(\Base $f3): void
    {
        if (!$this->requireAdmin()) return;

        $userId = (int) $f3->get('PARAMS.id');
        $user = $this->db->exec('SELECT id, name, email, level, points, joined, lastVisit, faction, alliance, mute FROM users WHERE id = ?', [$userId]);

        if (empty($user)) {
            $this->flashAndRedirect('User not found.', '/admin');
            return;
        }

        $user = $user[0];
        $user['avatar'] = GravatarHelper::url($user['name'], 80);

        $towns = $this->db->exec('SELECT id, name, resources, limits, population FROM towns WHERE owner = ? ORDER BY id', [$userId]);

        foreach ($towns as &$town) {
            $res = DataParser::parseResources($town['resources']);
            $town['res_crop']   = (int) $res['crop'];
            $town['res_lumber'] = (int) $res['lumber'];
            $town['res_stone']  = (int) $res['stone'];
            $town['res_iron']   = (int) $res['iron'];
            $town['res_gold']   = (int) $res['gold'];
        }
        unset($town);

        $this->render('admin/user-detail.html', [
            'page_title' => 'User Detail: ' . $user['name'],
            'detail_user' => $user,
            'detail_towns' => $towns,
        ]);
    }

    public function resetPassword(\Base $f3): void
    {
        if (!$this->requireAdmin()) return;

        $userId = (int) $f3->get('PARAMS.id');
        if (!$this->requireCsrf('/admin/user/' . $userId)) return;

        $newPass = trim($this->post('new_password', ''));

        if (empty($newPass)) {
            $this->flashAndRedirect('Password cannot be empty.', '/admin/user/' . $userId);
            return;
        }

        $hash = password_hash($newPass, PASSWORD_BCRYPT);
        $this->db->exec('UPDATE users SET pass = ? WHERE id = ?', [$hash, $userId]);

        $this->flashAndRedirect('Password updated successfully.', '/admin/user/' . $userId);
    }

    public function updateResources(\Base $f3): void
    {
        if (!$this->requireAdmin()) return;

        $userId = (int) $f3->get('PARAMS.id');
        if (!$this->requireCsrf('/admin/user/' . $userId)) return;

        $townId = InputSanitizer::cleanInt($this->post('town_id', 0));

        // Verify town belongs to this user
        $town = $this->db->exec('SELECT id FROM towns WHERE id = ? AND owner = ?', [$townId, $userId]);
        if (empty($town)) {
            $this->flashAndRedirect('Town not found.', '/admin/user/' . $userId);
            return;
        }

        $crop   = max(0, InputSanitizer::cleanInt($this->post('crop', 0)));
        $lumber = max(0, InputSanitizer::cleanInt($this->post('lumber', 0)));
        $stone  = max(0, InputSanitizer::cleanInt($this->post('stone', 0)));
        $iron   = max(0, InputSanitizer::cleanInt($this->post('iron', 0)));
        $gold   = max(0, InputSanitizer::cleanInt($this->post('gold', 0)));

        $resources = implode('-', [$crop, $lumber, $stone, $iron, $gold]);
        $this->db->exec('UPDATE towns SET resources = ? WHERE id = ?', [$resources, $townId]);

        $this->flashAndRedirect('Resources updated successfully.', '/admin/user/' . $userId);
    }

    public function deleteBackground(\Base $f3): void
    {
        if (!$this->requireAdmin('/towns')) return;
        if (!$this->requireCsrf('/admin')) return;

        $filename = basename((string) $this->post('filename', ''));
        $available = $this->listBackgrounds();
        if (!in_array($filename, $available, true)) {
            $this->flashAndRedirect('Invalid filename.', '/admin');
            return;
        }

        $currentDefault = $this->getConfigValue('town_background_default', 'back.png');
        if ($filename === $currentDefault) {
            $this->flashAndRedirect('Cannot delete the active default background. Choose another default first.', '/admin');
            return;
        }

        $path = $this->getBackgroundDir() . $filename;
        if (!is_file($path)) {
            $this->flashAndRedirect('File not found.', '/admin');
            return;
        }

        unlink($path);
        $this->flashAndRedirect('Background "' . $filename . '" deleted.', '/admin');
    }

    public function editBackgroundMask(\Base $f3, array $params = []): void
    {
        if (!$this->requireAdmin('/towns')) return;

        $filename = basename((string) ($params['filename'] ?? ''));
        $available = $this->listBackgrounds();
        if (!in_array($filename, $available, true)) {
            $this->flashAndRedirect('Invalid background.', '/admin');
            return;
        }

        $key = $this->backgroundMaskConfigKey($filename);
        $raw = $this->getConfigValue($key, '[]');
        $decoded = json_decode($raw, true);
        $zones = $this->normalizeBackgroundMaskZones($decoded);

        $this->render('admin/background-mask.html', [
            'page_title' => 'Background Mask: ' . $filename,
            'mask_background' => $filename,
            'mask_zones' => $zones,
            'backgrounds' => $available,
        ]);
    }

    public function saveBackgroundMask(\Base $f3, array $params = []): void
    {
        if (!$this->requireAdmin('/towns')) return;
        if (!$this->requireCsrf('/admin')) return;

        $filename = basename((string) ($params['filename'] ?? ''));
        $available = $this->listBackgrounds();
        if (!in_array($filename, $available, true)) {
            $this->flashAndRedirect('Invalid background.', '/admin');
            return;
        }

        $zonesJson = (string) $this->post('zones_json', '[]');
        $decoded = json_decode($zonesJson, true);
        $zones = $this->normalizeBackgroundMaskZones($decoded);
        $key = $this->backgroundMaskConfigKey($filename);
        $this->setConfigValue($key, json_encode($zones));

        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'zones' => $zones]);
    }

    public function assets(\Base $f3): void
    {
        if (!$this->requireAdmin('/towns')) return;

        $assetDir = $this->getAssetDir();
        $lang = (array) $f3->get('lang');
        $pickLabel = static function (array $langData, string $key, string $fallback): string {
            $value = $langData[$key] ?? null;
            return is_string($value) && $value !== '' ? $value : $fallback;
        };

        $buildings = $this->db->exec(
            'SELECT type, MIN(name) AS name FROM buildings GROUP BY type ORDER BY type'
        );
        $units = $this->db->exec(
            'SELECT type, MIN(name) AS name FROM units GROUP BY type ORDER BY type'
        );
        $weapons = $this->db->exec(
            'SELECT type, MIN(name) AS name FROM weapons GROUP BY type ORDER BY type'
        );

        $buildingAssets = [];
        foreach ($buildings as $b) {
            $type = (int) ($b['type'] ?? 0);
            $name = (string) ($b['name'] ?? ('Building ' . $type));
            $nameParts = explode('-', $name);
            $displayName = $nameParts[0] ?? $name;
            $file = 'b' . $type . '.png';
            $buildingAssets[] = [
                'id' => $type,
                'name' => $displayName,
                'file' => $file,
                'exists' => file_exists($assetDir . $file),
            ];
        }

        $unitAssets = [];
        foreach ($units as $u) {
            $type = (int) ($u['type'] ?? 0);
            $name = (string) ($u['name'] ?? ('Unit ' . $type));
            $file = '2' . $type . '.gif';
            $unitAssets[] = [
                'id' => $type,
                'name' => $name,
                'file' => $file,
                'exists' => file_exists($assetDir . $file),
            ];
        }

        $weaponAssets = [];
        foreach ($weapons as $w) {
            $type = (int) ($w['type'] ?? 0);
            $name = (string) ($w['name'] ?? ('Weapon ' . $type));
            $file = '1' . $type . '.gif';
            $weaponAssets[] = [
                'id' => $type,
                'name' => $name,
                'file' => $file,
                'exists' => file_exists($assetDir . $file),
            ];
        }

        $resourceAssets = [];
        $resourceNames = ['Crop', 'Lumber', 'Stone', 'Iron', 'Gold'];
        for ($i = 0; $i <= 4; $i++) {
            $file = sprintf('%02d.gif', $i);
            $resourceAssets[] = [
                'id' => $i,
                'name' => $resourceNames[$i] ?? ('Resource ' . $i),
                'file' => $file,
                'exists' => file_exists($assetDir . $file),
            ];
        }

        $this->render('admin/assets.html', [
            'page_title' => 'Asset Editor',
            'asset_buildings' => $buildingAssets,
            'asset_units' => $unitAssets,
            'asset_weapons' => $weaponAssets,
            'asset_resources' => $resourceAssets,
            'asset_labels' => [
                'building' => $pickLabel($lang, 'building', 'Building'),
                'unit' => $pickLabel($lang, 'unit', 'Unit'),
                'weapon' => $pickLabel($lang, 'weapon', 'Weapon'),
                'resource' => $pickLabel($lang, 'resource', 'Resource'),
                'units_tab' => $pickLabel($lang, 'unitImages', 'Units'),
                'weapons_tab' => $pickLabel($lang, 'weaponImages', 'Weapons'),
                'resources_tab' => $pickLabel($lang, 'resourceImages', 'Resources'),
            ],
        ]);
    }

    public function createCamp(\Base $f3): void
    {
        if (!$this->requireAdmin()) return;
        if (!$this->requireCsrf('/admin')) return;

        $x     = InputSanitizer::cleanInt($this->post('x', 0));
        $y     = InputSanitizer::cleanInt($this->post('y', 0));
        $level = max(1, min(5, InputSanitizer::cleanInt($this->post('level', 1))));

        // Validate tile exists and is not water
        $tile = $this->db->exec('SELECT type FROM map WHERE x = ? AND y = ? LIMIT 1', [$x, $y]);
        if (empty($tile) || (int) $tile[0]['type'] === 2) {
            $this->flashAndRedirect('Invalid coordinates (water or outside map).', '/admin#camps');
            return;
        }

        $barbarianService = new BarbarianService($this->db);
        $id = $barbarianService->createCamp($x, $y, $level);

        if ($id > 0) {
            $this->flashAndRedirect("Camp #$id created at ($x,$y) Level $level.", '/admin#camps');
        } else {
            $this->flashAndRedirect('Failed to create camp.', '/admin#camps');
        }
    }

    public function editCamp(\Base $f3): void
    {
        if (!$this->requireAdmin()) return;

        $id = (int) $f3->get('PARAMS.id');
        $barbarianService = new BarbarianService($this->db);
        $camp = $barbarianService->getCampById($id);

        if ($camp === null) {
            $this->flashAndRedirect('Camp not found.', '/admin#camps');
            return;
        }

        $this->render('admin/camps/edit.html', [
            'page_title' => 'Edit Camp #' . $id,
            'camp'       => $camp,
        ]);
    }

    public function updateCamp(\Base $f3): void
    {
        if (!$this->requireAdmin()) return;

        $id = (int) $f3->get('PARAMS.id');
        if (!$this->requireCsrf('/admin/camps/' . $id . '/edit')) return;

        $x         = InputSanitizer::cleanInt($this->post('x', 0));
        $y         = InputSanitizer::cleanInt($this->post('y', 0));
        $level     = max(1, min(5, InputSanitizer::cleanInt($this->post('level', 1))));
        $active    = (bool) InputSanitizer::cleanInt($this->post('active', 1));
        $army      = trim($this->post('army', ''));
        $resources = trim($this->post('resources', ''));

        // Validate army format (13 hyphen-separated ints)
        if (!preg_match('/^\d+(-\d+){12}$/', $army)) {
            $this->flashAndRedirect('Invalid army format (needs 13 values).', '/admin/camps/' . $id . '/edit');
            return;
        }
        // Validate resources format (5 hyphen-separated ints)
        if (!preg_match('/^\d+(-\d+){4}$/', $resources)) {
            $this->flashAndRedirect('Invalid resources format (needs 5 values).', '/admin/camps/' . $id . '/edit');
            return;
        }

        $barbarianService = new BarbarianService($this->db);
        $barbarianService->updateCamp($id, $x, $y, $level, $active, $army, $resources);

        $this->flashAndRedirect("Camp #$id updated.", '/admin#camps');
    }

    public function deleteCamp(\Base $f3): void
    {
        if (!$this->requireAdmin()) return;
        if (!$this->requireCsrf('/admin')) return;

        $id = InputSanitizer::cleanInt($this->post('camp_id', 0));
        if ($id > 0) {
            $barbarianService = new BarbarianService($this->db);
            $barbarianService->deleteCamp($id);
        }

        $this->flashAndRedirect("Camp #$id deleted.", '/admin#camps');
    }

    // =========================================================================
    // Bot management
    // =========================================================================

    public function botsConfig(\Base $f3): void
    {
        if (!$this->requireAdmin()) return;
        if (!$this->requireCsrf('/admin')) return;

        $active     = InputSanitizer::cleanInt($this->post('bot_active', 0)) ? 1 : 0;
        $count      = max(0, min(200, InputSanitizer::cleanInt($this->post('bot_count', 0))));
        $aggression = max(1, min(5, InputSanitizer::cleanInt($this->post('bot_aggression', 2))));

        foreach ([
            'bot_active'     => (string) $active,
            'bot_count'      => (string) $count,
            'bot_aggression' => (string) $aggression,
        ] as $name => $value) {
            $this->setConfigValue($name, $value);
        }

        $this->flashAndRedirect('Bot configuration saved.', '/admin#bots');
    }

    public function botsSpawn(\Base $f3): void
    {
        if (!$this->requireAdmin()) return;
        if (!$this->requireCsrf('/admin')) return;

        $botService = new BotService($this->db);
        $spawned    = $botService->spawnMissingBots();

        $this->flashAndRedirect("Spawned {$spawned} bot(s).", '/admin#bots');
    }

    public function createFaction(\Base $f3): void
    {
        if (!$this->requireAdmin()) return;
        if (!$this->requireCsrf('/admin')) return;

        $name = InputSanitizer::clean((string) $this->post('name', ''));
        $templateId = InputSanitizer::cleanInt($this->post('template_faction', 1));
        $grPathRaw = trim((string) $this->post('gr_path', '1/'));
        $ratioRaw = str_replace(',', '.', trim((string) $this->post('ratio', '1.5')));
        $ratio = (float) $ratioRaw;

        $factionService = new FactionService($this->db);
        $newId = $factionService->createFromTemplate($name, $templateId, $grPathRaw, $ratio);
        if ($newId <= 0) {
            $this->flashAndRedirect('Faction could not be created. Check inputs and template data.', '/admin#factions');
            return;
        }

        $this->flashAndRedirect("Faction #{$newId} created.", '/admin#factions');
    }

    public function updateFaction(\Base $f3): void
    {
        if (!$this->requireAdmin()) return;
        if (!$this->requireCsrf('/admin')) return;

        $factionId = InputSanitizer::cleanInt($this->post('faction_id', 0));
        $name = InputSanitizer::clean((string) $this->post('name', ''));
        $grPathRaw = trim((string) $this->post('gr_path', '1/'));
        $ratioRaw = str_replace(',', '.', trim((string) $this->post('ratio', '1.5')));
        $ratio = (float) $ratioRaw;

        $factionService = new FactionService($this->db);
        if (!$factionService->updateMeta($factionId, $name, $grPathRaw, $ratio)) {
            $this->flashAndRedirect('Faction update failed. Please check fields.', '/admin#factions');
            return;
        }

        $this->flashAndRedirect("Faction #{$factionId} updated.", '/admin#factions');
    }

    public function deleteFaction(\Base $f3): void
    {
        if (!$this->requireAdmin()) return;
        if (!$this->requireCsrf('/admin')) return;

        $factionId = InputSanitizer::cleanInt($this->post('faction_id', 0));
        if ($factionId <= 0) {
            $this->flashAndRedirect('Invalid faction ID.', '/admin#factions');
            return;
        }

        // Keep base factions protected to avoid breaking default game assumptions.
        if ($factionId <= 3) {
            $this->flashAndRedirect('Core factions (1-3) cannot be deleted.', '/admin#factions');
            return;
        }

        $factionService = new FactionService($this->db);
        if ($factionService->countAll() <= 1) {
            $this->flashAndRedirect('At least one faction must remain.', '/admin#factions');
            return;
        }

        if ($factionService->countUsersByFaction($factionId) > 0) {
            $this->flashAndRedirect('Cannot delete faction with active users.', '/admin#factions');
            return;
        }

        if (!$factionService->deleteFaction($factionId)) {
            $this->flashAndRedirect('Faction delete failed.', '/admin#factions');
            return;
        }

        $this->flashAndRedirect("Faction #{$factionId} deleted.", '/admin#factions');
    }

    public function botDelete(\Base $f3): void
    {
        if (!$this->requireAdmin()) return;
        if (!$this->requireCsrf('/admin')) return;

        $botId = InputSanitizer::cleanInt($this->post('bot_id', 0));
        if ($botId <= 0) {
            $this->flashAndRedirect('Invalid bot ID.', '/admin#bots');
            return;
        }

        $botService = new BotService($this->db);
        $botService->deleteBot($botId);

        $this->flashAndRedirect("Bot #{$botId} deleted.", '/admin#bots');
    }

    public function missionTemplateCreate(\Base $f3): void
    {
        if (!$this->requireAdmin()) return;
        if (!$this->requireCsrf('/admin')) return;
        $this->ensureMissionTemplateSchema();

        $type = InputSanitizer::cleanInt($this->post('type', MissionService::TYPE_BUILD));
        if (!in_array($type, MissionService::allTypes(), true)) {
            $this->flashAndRedirect('Invalid mission type.', '/admin#missions');
            return;
        }

        $titleTr = trim((string) $this->post('title_tr', ''));
        $titleEn = trim((string) $this->post('title_en', ''));
        $targetMin = max(1, InputSanitizer::cleanInt($this->post('target_min', 1)));
        $targetMax = max(1, InputSanitizer::cleanInt($this->post('target_max', $targetMin)));
        if ($targetMax < $targetMin) {
            $tmp = $targetMin;
            $targetMin = $targetMax;
            $targetMax = $tmp;
        }

        $rewardXp = max(0, InputSanitizer::cleanInt($this->post('reward_xp', 100)));
        $rewardResourceIndex = max(0, min(4, InputSanitizer::cleanInt($this->post('reward_resource_index', 0))));
        $rewardResourceAmount = max(0, InputSanitizer::cleanInt($this->post('reward_resource_amount', 200)));
        $isActive = InputSanitizer::cleanInt($this->post('is_active', 1)) ? 1 : 0;

        if ($titleTr === '') {
            $titleTr = 'Gorev {n}';
        }
        if ($titleEn === '') {
            $titleEn = 'Mission {n}';
        }

        $sortOrderRow = $this->db->exec('SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_sort FROM mission_templates');
        $sortOrder = (int) ($sortOrderRow[0]['next_sort'] ?? 1);

        $this->db->exec(
            'INSERT INTO mission_templates
             (type, title_tr, title_en, target_min, target_max, reward_xp, reward_resource_index, reward_resource_amount, is_active, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$type, $titleTr, $titleEn, $targetMin, $targetMax, $rewardXp, $rewardResourceIndex, $rewardResourceAmount, $isActive, $sortOrder]
        );

        $this->flashAndRedirect('Mission template created.', '/admin#missions');
    }

    public function missionTemplateDelete(\Base $f3): void
    {
        if (!$this->requireAdmin()) return;
        if (!$this->requireCsrf('/admin')) return;
        $this->ensureMissionTemplateSchema();

        $templateId = InputSanitizer::cleanInt($this->post('template_id', 0));
        if ($templateId <= 0) {
            $this->flashAndRedirect('Invalid template ID.', '/admin#missions');
            return;
        }

        $this->db->exec('DELETE FROM mission_templates WHERE id = ? LIMIT 1', [$templateId]);
        $this->flashAndRedirect("Mission template #{$templateId} deleted.", '/admin#missions');
    }

    public function uploadAsset(\Base $f3): void
    {
        if (!$this->requireAdmin('/towns')) return;
        if (!$this->requireCsrf('/admin/assets')) return;

        $category = InputSanitizer::clean((string) $this->post('category', ''));
        $assetId = max(0, InputSanitizer::cleanInt($this->post('asset_id', 0)));
        $customFilename = trim((string) $this->post('filename', ''));

        $file = $f3->get('FILES.asset_file');
        if (!$file || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->flashAndRedirect('No file uploaded.', '/admin/assets');
            return;
        }

        $allowedMimes = [
            'image/gif' => 'gif',
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
        ];

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($file['tmp_name']);
        if (!isset($allowedMimes[$mime])) {
            $this->flashAndRedirect('Only GIF, PNG, JPG or WEBP images are allowed.', '/admin/assets');
            return;
        }
        $ext = $allowedMimes[$mime];

        $targetName = '';
        if ($customFilename !== '') {
            $safeCustom = basename($customFilename);
            if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,80}$/', $safeCustom)) {
                $this->flashAndRedirect('Invalid filename.', '/admin/assets');
                return;
            }
            $targetName = $safeCustom;
        } else {
            $targetName = match ($category) {
                'building' => 'b' . $assetId . '.png',
                'unit' => '2' . $assetId . '.gif',
                'weapon' => '1' . $assetId . '.gif',
                'resource' => sprintf('%02d.gif', $assetId),
                default => '',
            };
            if ($targetName === '') {
                $this->flashAndRedirect('Invalid asset category.', '/admin/assets');
                return;
            }
        }

        // If user chooses a derived name, keep the expected extension.
        if ($customFilename === '') {
            $expectedExt = strtolower(pathinfo($targetName, PATHINFO_EXTENSION));
            if ($expectedExt !== $ext) {
                $this->flashAndRedirect('File type does not match expected extension for this asset.', '/admin/assets');
                return;
            }
        }

        $assetDir = $this->getAssetDir();
        $targetPath = $assetDir . $targetName;

        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            $this->flashAndRedirect('Failed to save asset file.', '/admin/assets');
            return;
        }

        $this->flashAndRedirect('Asset uploaded: ' . $targetName, '/admin/assets');
    }

    // ── Achievements ──────────────────────────────────────

    public function achievementCreate(\Base $f3): void
    {
        if (!$this->requireAdmin()) return;
        if (!$this->requireCsrf('/admin')) return;

        $code      = $this->sanitizeCode($this->post('code', ''));
        $titleTr   = trim($this->post('title_tr', ''));
        $titleEn   = trim($this->post('title_en', ''));
        $descTr    = trim($this->post('desc_tr', ''));
        $descEn    = trim($this->post('desc_en', ''));
        $category  = InputSanitizer::cleanInt($this->post('category', 0));
        $threshold = max(1, InputSanitizer::cleanInt($this->post('threshold', 1)));
        $rewardXp  = max(0, InputSanitizer::cleanInt($this->post('reward_xp', 0)));
        $sortOrder = max(0, InputSanitizer::cleanInt($this->post('sort_order', 0)));

        if (empty($titleEn)) {
            $this->flashAndRedirect('Title (EN) is required.', '/admin#achievements');
            return;
        }

        $service = new AchievementService($this->db);
        $service->create($code, $titleTr ?: $titleEn, $titleEn, $descTr, $descEn, $category, $threshold, $rewardXp, $sortOrder);
        $this->flashAndRedirect('Achievement created.', '/admin#achievements');
    }

    public function achievementUpdate(\Base $f3): void
    {
        if (!$this->requireAdmin()) return;
        if (!$this->requireCsrf('/admin')) return;

        $id       = InputSanitizer::cleanInt($this->post('id', 0));
        $titleTr  = trim($this->post('title_tr', ''));
        $titleEn  = trim($this->post('title_en', ''));
        $descTr   = trim($this->post('desc_tr', ''));
        $descEn   = trim($this->post('desc_en', ''));
        $category = InputSanitizer::cleanInt($this->post('category', 0));
        $threshold= max(1, InputSanitizer::cleanInt($this->post('threshold', 1)));
        $rewardXp = max(0, InputSanitizer::cleanInt($this->post('reward_xp', 0)));
        $isActive = InputSanitizer::cleanInt($this->post('is_active', 1)) ? 1 : 0;
        $sortOrder= max(0, InputSanitizer::cleanInt($this->post('sort_order', 0)));

        if ($id <= 0) {
            $this->flashAndRedirect('Invalid achievement.', '/admin#achievements');
            return;
        }

        (new AchievementService($this->db))->update($id, $titleTr ?: $titleEn, $titleEn, $descTr, $descEn, $category, $threshold, $rewardXp, $isActive, $sortOrder);
        $this->flashAndRedirect('Achievement updated.', '/admin#achievements');
    }

    public function achievementDelete(\Base $f3): void
    {
        if (!$this->requireAdmin()) return;
        if (!$this->requireCsrf('/admin')) return;

        $id = InputSanitizer::cleanInt($this->post('id', 0));
        if ($id > 0) {
            (new AchievementService($this->db))->delete($id);
        }
        $this->flashAndRedirect('Achievement deleted.', '/admin#achievements');
    }

    // ── Seasons ──────────────────────────────────────────

    public function seasonCreate(\Base $f3): void
    {
        if (!$this->requireAdmin()) return;
        if (!$this->requireCsrf('/admin')) return;

        $name      = trim($this->post('name', ''));
        $startTime = trim($this->post('start_time', ''));
        $endTime   = trim($this->post('end_time', ''));

        if (empty($name) || empty($startTime) || empty($endTime)) {
            $this->flashAndRedirect('All season fields are required.', '/admin#seasons');
            return;
        }

        (new SeasonService($this->db))->createSeason($name, $startTime, $endTime);
        $this->flashAndRedirect('Season created.', '/admin#seasons');
    }

    public function seasonActivate(\Base $f3): void
    {
        if (!$this->requireAdmin()) return;
        if (!$this->requireCsrf('/admin')) return;

        $id = InputSanitizer::cleanInt($this->post('season_id', 0));
        if ($id <= 0) {
            $this->flashAndRedirect('Invalid season.', '/admin#seasons');
            return;
        }

        (new SeasonService($this->db))->activateSeason($id);
        $this->flashAndRedirect('Season activated.', '/admin#seasons');
    }

    public function seasonEnd(\Base $f3): void
    {
        if (!$this->requireAdmin()) return;
        if (!$this->requireCsrf('/admin')) return;

        $id = InputSanitizer::cleanInt($this->post('season_id', 0));
        if ($id <= 0) {
            $this->flashAndRedirect('Invalid season.', '/admin#seasons');
            return;
        }

        (new SeasonService($this->db))->forceEndSeason($id);
        $this->flashAndRedirect('Season ended.', '/admin#seasons');
    }

    public function seasonReset(\Base $f3): void
    {
        if (!$this->requireAdmin()) return;
        if (!$this->requireCsrf('/admin')) return;

        $confirm = trim($this->post('confirm', ''));
        if ($confirm !== 'RESET') {
            $this->flashAndRedirect('Please type RESET to confirm.', '/admin#seasons');
            return;
        }

        (new SeasonService($this->db))->resetSeasonData();
        $this->flashAndRedirect('Season data reset.', '/admin#seasons');
    }

    // ── Alliance Wars ────────────────────────────────────

    public function warEnd(\Base $f3): void
    {
        if (!$this->requireAdmin()) return;
        if (!$this->requireCsrf('/admin')) return;

        $id = InputSanitizer::cleanInt($this->post('war_id', 0));
        if ($id <= 0) {
            $this->flashAndRedirect('Invalid war.', '/admin#wars');
            return;
        }

        (new AllianceWarService($this->db))->processWarEnd($id);
        $this->flashAndRedirect('War ended.', '/admin#wars');
    }
}

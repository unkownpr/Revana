<?php declare(strict_types=1);

namespace Devana\Controllers;

use Devana\Traits\CsrfProtection;
use Devana\Services\TownService;
use Devana\Services\StatsService;
use Devana\Services\GameTickService;
use Devana\Services\MissionService;
use Devana\Services\PreferenceService;
use Devana\Services\RecaptchaService;
use Devana\Services\SeasonService;
use Devana\Enums\UserRole;
use Devana\Helpers\AvatarHelper;

abstract class Controller
{
    use CsrfProtection;

    protected \Base $f3;
    protected ?\DB\SQL $db;
    private ?TownService $townService = null;
    private ?bool $hasUsersPremiumBalanceColumn = null;

    public function __construct()
    {
        $this->f3 = \Base::instance();
        $this->db = $this->f3->get('DB') ?: null;

        if ($this->db) {
            $this->ensureUsersPremiumBalanceColumn();
            $this->loadRuntimeConfigFromDb();
            $path = (string) ($this->f3->get('PATH') ?? '');
            if (!$this->f3->exists('runtime_tick_done') && !str_starts_with($path, '/install')) {
                try {
                    (new GameTickService($this->db))->tickDueQueues();
                } catch (\Throwable $e) {
                    // Skip ticking if queue tables are not ready (e.g. during install/migration).
                }
                $this->f3->set('runtime_tick_done', true);
            }
        }
    }

    // ── Guard methods ────────────────────────────────────

    /**
     * Require an authenticated user. Redirects to login if not logged in.
     */
    protected function requireAuth(): ?array
    {
        $user = $this->currentUser();
        if ($user === null) {
            $this->redirect('/login');
            return null;
        }
        return $user;
    }

    /**
     * Validate CSRF token and redirect on failure.
     * Returns true if valid, false (with redirect) if not.
     */
    protected function requireCsrf(string $redirectUrl): bool
    {
        if (!$this->validateCsrfToken()) {
            $this->flashAndRedirect('Invalid request.', $redirectUrl);
            return false;
        }
        return true;
    }

    protected function requireRecaptcha(string $redirectUrl): bool
    {
        $enabled = (int) ($this->f3->get('game.recaptcha_enabled') ?? 0) === 1;
        $siteKey = trim((string) ($this->f3->get('game.recaptcha_site_key') ?? ''));
        $secretKey = trim((string) ($this->f3->get('game.recaptcha_secret_key') ?? ''));

        if (!$enabled) {
            return true;
        }

        if ($siteKey === '' || $secretKey === '') {
            $this->flashAndRedirect('reCAPTCHA is enabled but keys are missing.', $redirectUrl);
            return false;
        }

        $token = trim((string) $this->post('g-recaptcha-response', ''));
        if ($token === '') {
            $this->flashAndRedirect('Please complete the reCAPTCHA verification.', $redirectUrl);
            return false;
        }

        $recaptcha = new RecaptchaService($secretKey);
        $ok = $recaptcha->verify($token, $_SERVER['REMOTE_ADDR'] ?? null);
        if (!$ok) {
            $this->flashAndRedirect('reCAPTCHA verification failed.', $redirectUrl);
            return false;
        }

        return true;
    }

    /**
     * Require that the current user owns the given town.
     * Returns the town row on success, null (with redirect) on failure.
     */
    protected function requireOwnedTown(int $townId, string $redirectUrl = '/towns'): ?array
    {
        $ts = $this->townService();
        $town = $ts->findById($townId);

        if ($town === null || (int) $town['owner'] !== ($this->currentUser()['id'] ?? 0)) {
            $this->flashAndRedirect('Town not found.', $redirectUrl);
            return null;
        }

        return $town;
    }

    /**
     * Require that the current user owns the given town (lightweight check, no data returned).
     */
    protected function requireTownOwnership(int $townId, string $redirectUrl = '/towns'): bool
    {
        if (!$this->townService()->isOwnedBy($townId, $this->currentUser()['id'] ?? 0)) {
            $this->flashAndRedirect('Town not found.', $redirectUrl);
            return false;
        }
        return true;
    }

    /**
     * Require admin access level.
     */
    protected function requireAdmin(string $redirectUrl = '/towns'): bool
    {
        $user = $this->currentUser();
        if (!UserRole::isAdmin((int) ($user['level'] ?? 0))) {
            $this->flashAndRedirect('Access denied.', $redirectUrl);
            return false;
        }
        return true;
    }

    /**
     * Require owner (super-admin) access level.
     */
    protected function requireOwner(string $redirectUrl = '/admin'): bool
    {
        $user = $this->currentUser();
        if (!UserRole::isOwner((int) ($user['level'] ?? 0))) {
            $this->flashAndRedirect('Access denied.', $redirectUrl);
            return false;
        }
        return true;
    }

    /**
     * Update a single field in the session user array.
     */
    protected function updateSession(string $key, mixed $value): void
    {
        $sessionUser = $this->f3->get('SESSION.user');
        $sessionUser[$key] = $value;
        $this->f3->set('SESSION.user', $sessionUser);
    }

    // ── Lazy service accessor ────────────────────────────

    protected function townService(): TownService
    {
        return $this->townService ??= new TownService($this->db);
    }

    protected function hasUsersPremiumBalanceColumn(): bool
    {
        if ($this->hasUsersPremiumBalanceColumn !== null) {
            return $this->hasUsersPremiumBalanceColumn;
        }

        if (!$this->db) {
            $this->hasUsersPremiumBalanceColumn = false;
            return false;
        }

        try {
            $rows = $this->db->exec("SHOW COLUMNS FROM `users` LIKE 'premium_balance'");
            $this->hasUsersPremiumBalanceColumn = !empty($rows);
        } catch (\Throwable $e) {
            $this->hasUsersPremiumBalanceColumn = false;
        }

        return $this->hasUsersPremiumBalanceColumn;
    }

    protected function ensureUsersPremiumBalanceColumn(): void
    {
        if (!$this->db) {
            $this->hasUsersPremiumBalanceColumn = false;
            return;
        }

        if ($this->hasUsersPremiumBalanceColumn()) {
            return;
        }

        try {
            $this->db->exec(
                "ALTER TABLE `users`
                 ADD COLUMN `premium_balance` INT UNSIGNED NOT NULL DEFAULT 0
                 AFTER `points`"
            );
        } catch (\Throwable $e) {
            // DB user may not have ALTER permission; keep fallback behavior.
        }

        $this->hasUsersPremiumBalanceColumn = null;
        $this->hasUsersPremiumBalanceColumn();
    }

    protected function render(string $template, array $data = []): void
    {
        foreach ($data as $key => $value) {
            $this->f3->set($key, $value);
        }

        $path = (string) ($this->f3->get('PATH') ?? '');
        $isAdminTemplate = str_starts_with($template, 'admin/');
        $isAdminPath = str_starts_with($path, '/admin');
        $isAdminPage = $isAdminTemplate || $isAdminPath;
        $adminSection = 'dashboard';
        if (str_starts_with($path, '/admin/maps')) {
            $adminSection = 'maps';
        } elseif (str_starts_with($path, '/admin/assets')) {
            $adminSection = 'assets';
        }
        $this->f3->set('is_admin_page', $isAdminPage);
        $this->f3->set('admin_section', $adminSection);
        $this->f3->set('body_class', $isAdminPage ? 'q_body admin-mode' : 'q_body');

        $this->loadLanguage();
        $this->setThemeVariables();
        $this->setCsrfToken();
        $this->loadFlashMessage();

        // Aktif sezon kontrolü — navbar linki için (istek başına 1 kez)
        if (!$this->f3->exists('has_active_season')) {
            try {
                $rows = $this->db->exec('SELECT id FROM seasons WHERE active = 1 LIMIT 1');
                $this->f3->set('has_active_season', !empty($rows));
            } catch (\Throwable $e) {
                $this->f3->set('has_active_season', false);
            }
        }

        $this->f3->set('content', $template);
        echo \Template::instance()->render('layout.html');
    }

    protected function renderMinimal(string $template, array $data = []): void
    {
        foreach ($data as $key => $value) {
            $this->f3->set($key, $value);
        }

        $this->setCsrfToken();
        echo \Template::instance()->render($template);
    }

    protected function redirect(string $url): void
    {
        $this->f3->reroute($url);
    }

    protected function flashAndRedirect(string $message, string $url = '/'): void
    {
        $this->f3->set('SESSION.flash', $message);
        $this->redirect($url);
    }

    /**
     * Translate a lang key (e.g. from a RuntimeException message).
     * Falls back to the raw string if the key is not found.
     */
    protected function translateKey(string $key): string
    {
        $this->loadLanguage();
        $lang = $this->f3->get('lang');
        return (is_array($lang) && isset($lang[$key])) ? (string) $lang[$key] : $key;
    }

    protected function currentUser(): ?array
    {
        return $this->f3->get('SESSION.user');
    }

    protected function isLoggedIn(): bool
    {
        return $this->f3->exists('SESSION.user');
    }

    protected function post(string $key, mixed $default = null): mixed
    {
        return $this->f3->get('POST.' . $key) ?? $default;
    }

    protected function get(string $key, mixed $default = null): mixed
    {
        return $this->f3->get('GET.' . $key) ?? $default;
    }

    private const LANGUAGE_LABELS = [
        'en' => 'English',
        'de' => 'Deutsch',
        'fr' => 'Français',
        'it' => 'Italiano',
        'nl' => 'Nederlands',
        'ro' => 'Română',
        'tr' => 'Türkçe',
    ];

    protected function loadLanguage(): void
    {
        if ($this->f3->exists('lang')) {
            return;
        }

        $langFile = 'en.php';

        if ($this->f3->exists('SESSION.user')) {
            $user = $this->f3->get('SESSION.user');
            $langFile = $user['language'] ?? 'en.php';
        } elseif ($this->f3->exists('SESSION.lang')) {
            $langFile = $this->f3->get('SESSION.lang');
        }

        // Normalize + sanitize: accept "tr" and "tr.php"
        $langFile = basename((string) $langFile);
        if (preg_match('/^[a-z]{2}$/', $langFile)) {
            $langFile .= '.php';
        }
        if (!preg_match('/^[a-z]{2}\.php$/', $langFile)) {
            $langFile = 'en.php';
        }
        if ($this->f3->exists('SESSION.user')) {
            $sessionUser = (array) $this->f3->get('SESSION.user');
            if (($sessionUser['language'] ?? null) !== $langFile) {
                $sessionUser['language'] = $langFile;
                $this->f3->set('SESSION.user', $sessionUser);
            }
        } elseif ($this->f3->exists('SESSION.lang') && $this->f3->get('SESSION.lang') !== $langFile) {
            $this->f3->set('SESSION.lang', $langFile);
        }

        $langPath = $this->f3->get('ROOT') . '/language/' . $langFile;
        $enPath = $this->f3->get('ROOT') . '/language/en.php';

        // Load English as base, then overlay selected language
        $baseLang = self::loadLangFile($enPath);
        $lang = $baseLang;
        if ($langFile !== 'en.php' && file_exists($langPath)) {
            $overlay = self::loadLangFile($langPath);
            foreach ($overlay as $k => $v) {
                $lang[$k] = $v;
            }
        }
        // Always guarantee newly introduced UI keys exist to prevent template notices.
        $lang = array_replace([
            'marketDesc' => 'Manage offers, exchange resources, and send supplies from one place.',
            'editLayout' => 'Edit Layout',
            'done' => 'Done',
            'inbox' => 'Inbox',
            'backToHome' => 'Back to Home',
            'goToMap' => 'Go to Map',
            'developedBy' => 'Developed by',
            'homeLoreTitle' => 'Chronicle of the Three Banners',
            'homeLoreText' => 'The old empires are broken, yet their roads, forts and markets remain. Build, trade and forge alliances before rival banners claim the frontier.',
            'constructionQueueFull' => 'Construction queue is full (max 3).',
            'inventory'          => 'Inventory',
            'menu'               => 'Menu',
            'premiumStore'       => 'Premium Store',
            'gems'               => 'Gems',
            'buyItem'            => 'Buy Item',
            // Admin panel v2 keys
            'dashboard'          => 'Dashboard',
            'mapsDesc'           => 'Create and edit game maps',
            'assetsDesc'         => 'Building, unit and weapon images',
            'statsDesc'          => 'Player and ranking statistics',
            'forumDesc'          => 'Forum topics and posts',
            'active'             => 'Active',
            'inactive'           => 'Inactive',
            'createCamp'         => 'Create Camp',
            'camps'              => 'camps',
            'confirmCleanForums' => 'Delete empty forum threads?',
            'confirmPurge'       => 'Delete old reports?',
            'cronWorker'         => 'Cron Worker',
            'everyMinute'        => 'Every minute',
            'cronDesc'           => 'Cron is required for resources and queues to flow.',
            'cronCommand'        => 'Enter the following in the Command field:',
            'announcementPlaceholder' => 'Write your announcement...',
            'contentRequired'    => 'Content is required.',
            'addAsset'           => 'Add New Image',
            'customFilename'     => 'Custom filename',
            'category'           => 'Category',
            'filename'           => 'Filename',
            'filenameExample'    => 'e.g. b99.png',
            'file'               => 'File',
            'buildingImages'     => 'Building Images',
            'unitImages'         => 'Unit Images',
            'weaponImages'       => 'Weapon Images',
            'resourceImages'     => 'Resource Images',
            'image'              => 'Image',
            'update'             => 'Update',
            'newMap'             => 'New Map',
            'createdAt'          => 'Created',
            'confirmActivateMap' => 'Deploy this map to the game?',
            'confirmDeleteMap'   => 'Delete this map permanently?',
            'noMaps'             => 'No maps yet.',
            'createFirstMap'     => 'Create new map',
            'activate'           => 'Activate',
            'hyphenSeparated'    => 'hyphen-separated',
            'values'             => 'values',
            'respawnNote'        => 'Setting active=Yes will clear the respawn timer.',
            'editCamp'           => 'Edit Camp',
            'townBackgrounds'    => 'Town Backgrounds',
            'uploadBackground'   => 'Upload New Background',
            'bgFilenameHint'     => 'Files are automatically named back.png, back2.png, ...',
            'upload'             => 'Upload',
            'delete'             => 'Delete',
            'confirmDelete'      => 'Are you sure? This cannot be undone.',
            'backToAdmin'        => 'Back to Admin',
            'bots'               => 'Bots',
            'botSettings'        => 'Bot Settings',
            'botSystem'          => 'Bot System',
            'botCount'           => 'Target Bot Count',
            'botAggression'      => 'Aggression (1-5)',
            'spawnBots'          => 'Spawn Bots',
            'spawnMissingBots'   => 'Spawn missing bots now',
            'spawnBotsDesc'      => 'Creates new bot accounts until the target count is reached.',
            'botCronWorker'      => 'Bot Cron Worker',
            'botCronDesc'        => 'Recommended interval for bots to act: every 5 minutes.',
            'activeBots'         => 'Active Bots',
            'botProfile'         => 'Profile',
            'noBots'             => 'No active bots yet.',
        ], $lang);
        $this->f3->set('lang', $lang);

        // Current language code for navbar
        $currentCode = str_replace('.php', '', $langFile);
        $this->f3->set('current_lang_code', strtoupper($currentCode));

        // Available languages list
        $langDir = $this->f3->get('ROOT') . '/language/';
        $available = [];
        foreach (glob($langDir . '*.php') as $file) {
            $code = basename($file, '.php');
            $available[] = [
                'code' => $code,
                'label' => self::LANGUAGE_LABELS[$code] ?? strtoupper($code),
            ];
        }
        $this->f3->set('available_languages', $available);
    }

    protected function langCatalogText(
        array $lang,
        string $catalog,
        int $faction,
        int $type,
        int $field,
        ?string $fallback = null
    ): ?string {
        if (!isset($lang[$catalog]) || !is_array($lang[$catalog])) {
            return $fallback;
        }

        $rows = $lang[$catalog];
        $candidateFactions = [$faction, $faction - 1];
        foreach ($candidateFactions as $fIdx) {
            if (isset($rows[$fIdx][$type][$field])) {
                return (string) $rows[$fIdx][$type][$field];
            }
        }

        foreach ($rows as $fRows) {
            if (isset($fRows[$type][$field])) {
                return (string) $fRows[$type][$field];
            }
        }

        return $fallback;
    }

    private function setThemeVariables(): void
    {
        $imgs = '/default/';
        $fimgs = '1/';

        if ($this->f3->exists('SESSION.user')) {
            $user = $this->f3->get('SESSION.user');
            $rawImgs = $user['imgs'] ?? 'default/';
            // Ensure leading slash for absolute paths
            $imgs = '/' . ltrim($rawImgs, '/');
            $fimgs = (string) ($user['fimgs'] ?? '1/');
            if ($fimgs === '' || substr($fimgs, -1) !== '/') {
                $fimgs .= '/';
            }

            // Some legacy accounts may point to a non-existing theme subfolder
            // (e.g. grPath=2/ while only default/1/ exists). Fall back safely.
            $imgRoot = rtrim((string) $this->f3->get('ROOT'), '/');
            $imgBasePath = $imgRoot . rtrim($imgs, '/');
            $fimgsPath = $imgBasePath . '/' . trim($fimgs, '/');
            if (!is_dir($fimgsPath)) {
                $fimgs = '1/';
            }

            $avatarSeed = (string) ($user['avatar_seed'] ?? $user['name'] ?? 'player');
            $avatarStyle = (string) ($user['avatar_style'] ?? AvatarHelper::DEFAULT_STYLE);
            $rawAvatarOptions = $user['avatar_options'] ?? null;

            if (is_array($rawAvatarOptions)) {
                $avatarOptions = AvatarHelper::normalize($rawAvatarOptions, $avatarSeed);
            } else {
                $avatarOptions = AvatarHelper::decodeAndNormalize(
                    is_string($rawAvatarOptions) ? $rawAvatarOptions : null,
                    $avatarSeed
                );
            }

            $this->f3->set('user_avatar', AvatarHelper::url($avatarStyle, $avatarOptions, 64));
            $this->f3->set('user_avatar_sm', AvatarHelper::url($avatarStyle, $avatarOptions, 24));

            // Navbar message/report counts + recent items for dropdowns
            if ($this->db) {
                $uid = (int) $user['id'];
                $msgCount = (int) ($this->db->exec('SELECT COUNT(*) AS cnt FROM messages WHERE recipient = ?', [$uid])[0]['cnt'] ?? 0);
                $repCount = (int) ($this->db->exec('SELECT COUNT(*) AS cnt FROM reports WHERE recipient = ?', [$uid])[0]['cnt'] ?? 0);
                $this->f3->set('nav_msg_count', $msgCount);
                $this->f3->set('nav_rep_count', $repCount);

                // Recent messages (last 5) with sender name
                $recentMsgs = $this->db->exec(
                    'SELECT m.id, m.subject, m.sent, u.name AS sender_name
                     FROM messages m LEFT JOIN users u ON m.sender = u.id
                     WHERE m.recipient = ? ORDER BY m.sent DESC LIMIT 5',
                    [$uid]
                );
                $this->f3->set('nav_recent_msgs', $recentMsgs);

                // Recent reports (last 5)
                $recentReps = $this->db->exec(
                    'SELECT id, subject, sent FROM reports
                     WHERE recipient = ? ORDER BY sent DESC LIMIT 5',
                    [$uid]
                );
                $this->f3->set('nav_recent_reps', $recentReps);

                // XP level for navbar
                try {
                    if ($this->hasUsersPremiumBalanceColumn()) {
                        $xpRow = $this->db->exec('SELECT xp, premium_balance FROM users WHERE id = ? LIMIT 1', [$uid]);
                    } else {
                        $xpRow = $this->db->exec('SELECT xp, 0 AS premium_balance FROM users WHERE id = ? LIMIT 1', [$uid]);
                    }
                    $userXp = (int) ($xpRow[0]['xp'] ?? 0);
                    $premiumBalance = (int) ($xpRow[0]['premium_balance'] ?? ($user['premium_balance'] ?? 0));
                    $this->f3->set('nav_xp_level', MissionService::getXpLevel($userXp));
                    $this->f3->set('nav_premium_balance', max(0, $premiumBalance));

                    if ((int) ($user['premium_balance'] ?? 0) !== $premiumBalance) {
                        $user['premium_balance'] = $premiumBalance;
                        $this->f3->set('SESSION.user', $user);
                    }
                } catch (\Throwable $e) {
                    $this->f3->set('nav_xp_level', 0);
                    $this->f3->set('nav_premium_balance', (int) ($user['premium_balance'] ?? 0));
                }

                // Nav town preference: preferred town shown as shortcut in navbar
                $prefService  = new PreferenceService($this->db);
                $navTownId    = (int) ($prefService->getAll($uid)['nav_town_id'] ?? 0);
                $nav_town     = null;
                if ($navTownId > 0) {
                    $tRow = $this->db->exec(
                        'SELECT id, name FROM towns WHERE id = ? AND owner = ? LIMIT 1',
                        [$navTownId, $uid]
                    );
                    if (!empty($tRow)) {
                        $nav_town = ['id' => (int) $tRow[0]['id'], 'name' => $tRow[0]['name']];
                    }
                }
                // Fallback: first town if no preference set
                if ($nav_town === null) {
                    $firstId = $this->townService()->getFirstTownId($uid);
                    if ($firstId !== null) {
                        $tRow = $this->db->exec('SELECT id, name FROM towns WHERE id = ? LIMIT 1', [$firstId]);
                        if (!empty($tRow)) {
                            $nav_town = ['id' => (int) $tRow[0]['id'], 'name' => $tRow[0]['name']];
                        }
                    }
                }
                $this->f3->set('nav_town', $nav_town);
            }
        }

        $this->f3->set('imgs', $imgs);
        $this->f3->set('fimgs', $fimgs);
        $this->f3->set('game_title', $this->f3->get('game.title') ?? 'Devana');
        $this->f3->set('site_logo_url', (string) ($this->f3->get('game.site_logo_path') ?? '/default/1/logo.jpg'));
        $this->f3->set('site_favicon_url', (string) ($this->f3->get('game.site_favicon_path') ?? '/default/1/logo.jpg'));
        $this->f3->set('meta_author', (string) ($this->f3->get('game.meta_author') ?? 'Devana'));
        $this->f3->set('meta_description', (string) ($this->f3->get('game.meta_description') ?? ($this->f3->get('game.title') ?? 'Devana')));
        $this->f3->set('meta_keywords', (string) ($this->f3->get('game.meta_keywords') ?? 'devana,strategy,game'));

        // Footer stats
        if ($this->db) {
            $statsService = new StatsService($this->db);
            $this->f3->set('footer_stats', $statsService->getFooterStats());
        }
    }

    private function setCsrfToken(): void
    {
        if (!$this->f3->exists('SESSION.csrf_token')) {
            $this->generateCsrfToken();
        }

        $this->f3->set('CSRF', $this->f3->get('SESSION.csrf_token'));
    }

    private static function loadLangFile(string $path): array
    {
        $lang = [];
        if (file_exists($path)) {
            include $path;
        }
        return $lang;
    }

    private function loadFlashMessage(): void
    {
        if ($this->f3->exists('SESSION.flash')) {
            $this->f3->set('flash_message', $this->f3->get('SESSION.flash'));
            $this->f3->set('flash_type', $this->f3->get('SESSION.flash_type') ?: 'info');
            $this->f3->clear('SESSION.flash');
            $this->f3->clear('SESSION.flash_type');
        }
    }

    private function loadRuntimeConfigFromDb(): void
    {
        if (!$this->db || $this->f3->exists('runtime_config_loaded')) {
            return;
        }

        try {
            $rows = $this->db->exec('SELECT name, value FROM config');
        } catch (\Throwable $e) {
            $this->f3->set('runtime_config_loaded', true);
            return;
        }
        $cfg = [];
        foreach ($rows as $row) {
            $key = (string) ($row['name'] ?? '');
            if ($key === '') {
                continue;
            }
            $cfg[$key] = (string) ($row['value'] ?? '');
        }

        $gameTitle = trim($cfg['game_name'] ?? '');
        if ($gameTitle !== '') {
            $this->f3->set('game.title', $gameTitle);
        }
        $this->f3->set('game.speed', (int) ($cfg['game_speed'] ?? 1));
        $this->f3->set('game.map_size', max(50, (int) ($cfg['map_size'] ?? 200)));
        $this->f3->set('game.max_towns', max(1, (int) ($cfg['max_towns'] ?? 3)));
        $this->f3->set('game.beginner_protection', max(0, (int) ($cfg['beginner_protection'] ?? 72)));
        $this->f3->set('game.registration_open', (int) ($cfg['register'] ?? 1));
        $this->f3->set('game.move_cost_crop', max(0, (int) ($cfg['move_cost_crop'] ?? 100)));
        $this->f3->set('game.recaptcha_enabled', (int) ($cfg['recaptcha_enabled'] ?? 0));
        $this->f3->set('game.recaptcha_site_key', (string) ($cfg['recaptcha_site_key'] ?? ''));
        $this->f3->set('game.recaptcha_secret_key', (string) ($cfg['recaptcha_secret_key'] ?? ''));
        $this->f3->set('game.default_lang', (string) ($cfg['default_lang'] ?? 'en.php'));
        $this->f3->set('game.mail_enabled', (int) ($cfg['mail_enabled'] ?? 0));
        $this->f3->set('game.login_max_attempts', max(1, (int) ($cfg['login_max_attempts'] ?? 5)));
        $this->f3->set('game.login_lock_minutes', max(1, (int) ($cfg['login_lock_minutes'] ?? 15)));
        $timezone = (string) ($cfg['timezone'] ?? 'UTC');
        if (@timezone_open($timezone) === false) {
            $timezone = 'UTC';
        }
        $this->f3->set('game.timezone', $timezone);
        @date_default_timezone_set($timezone);
        $this->f3->set('game.site_logo_path', (string) ($cfg['site_logo_path'] ?? '/default/1/logo.jpg'));
        $this->f3->set('game.site_favicon_path', (string) ($cfg['site_favicon_path'] ?? '/default/1/logo.jpg'));
        $this->f3->set('game.meta_author', (string) ($cfg['meta_author'] ?? 'Devana'));
        $this->f3->set('game.meta_description', (string) ($cfg['meta_description'] ?? ($gameTitle !== '' ? $gameTitle : 'Devana')));
        $this->f3->set('game.meta_keywords', (string) ($cfg['meta_keywords'] ?? 'devana,strategy,game'));
        $this->f3->set('runtime_config_loaded', true);
    }

    /**
     * @return array{hour:int, minute:int, second:int}
     */
    protected function getServerClock(): array
    {
        if ($this->db) {
            try {
                $row = $this->db->exec(
                    'SELECT HOUR(NOW()) AS h, MINUTE(NOW()) AS m, SECOND(NOW()) AS s LIMIT 1'
                );
                if (!empty($row)) {
                    return [
                        'hour' => max(0, min(23, (int) ($row[0]['h'] ?? 0))),
                        'minute' => max(0, min(59, (int) ($row[0]['m'] ?? 0))),
                        'second' => max(0, min(59, (int) ($row[0]['s'] ?? 0))),
                    ];
                }
            } catch (\Throwable $e) {
                // Fallback to PHP clock below.
            }
        }

        return [
            'hour' => (int) date('G'),
            'minute' => (int) date('i'),
            'second' => (int) date('s'),
        ];
    }
}

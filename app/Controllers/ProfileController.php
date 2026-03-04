<?php declare(strict_types=1);

namespace Devana\Controllers;

use Devana\Helpers\InputSanitizer;
use Devana\Helpers\AvatarHelper;
use Devana\Services\PreferenceService;
use Devana\Services\PremiumStoreService;

final class ProfileController extends Controller
{
    public function view(\Base $f3, array $params): void
    {
        $userId = (int) ($params['id'] ?? 0);
        $this->loadLanguage();
        $lang = (array) ($f3->get('lang') ?? []);

        $user = $this->db->exec('SELECT id, name, email, level, faction, alliance, `rank`, description, points, premium_balance, joined, lastVisit, avatar_seed, avatar_style, avatar_options FROM users WHERE id = ?', [$userId]);

        if (empty($user)) {
            $this->flashAndRedirect('User not found.', '/towns');
            return;
        }

        $user = $user[0];
        $towns = $this->db->exec(
            'SELECT t.id, t.name, t.population, t.isCapital AS is_capital, COALESCE(m.x, 0) AS x, COALESCE(m.y, 0) AS y
             FROM towns t LEFT JOIN map m ON m.type = 3 AND m.subtype = t.id
             WHERE t.owner = ? ORDER BY t.isCapital DESC',
            [$userId]
        );
        $alliance = null;

        if ((int) $user['alliance'] > 0) {
            $result = $this->db->exec('SELECT id, name FROM alliances WHERE id = ?', [(int) $user['alliance']]);
            $alliance = $result[0] ?? null;
        }

        // Get faction name
        $factionResult = $this->db->exec('SELECT name FROM factions WHERE id = ?', [(int) $user['faction']]);
        $factionNameMap = (array) ($lang['faction_names'] ?? []);
        $factionId = (int) ($user['faction'] ?? 0);
        $factionName = $factionNameMap[$factionId] ?? ($factionResult[0]['name'] ?? '');

        $avatarOptions = AvatarHelper::decodeAndNormalize($user['avatar_options'] ?? null, $user['avatar_seed'] ?? $user['name'] ?? '');
        $avatarStyle = $user['avatar_style'] ?? AvatarHelper::DEFAULT_STYLE;
        $activeCosmetics = [];
        try {
            $activeCosmetics = (new PremiumStoreService($this->db))->getActiveCosmetics((int) $user['id']);
        } catch (\Throwable $e) {
            $activeCosmetics = [];
        }

        $currentUserId = (int) ($this->currentUser()['id'] ?? 0);
        $isOwnProfile = $currentUserId === (int) $user['id'];
        $inventoryItems = [];
        $activeInventoryCosmetics = [];

        if ($isOwnProfile) {
            try {
                $storeService = new PremiumStoreService($this->db);
                $storeService->bootstrapCatalog();
                $inventoryItems = $storeService->getInventory((int) $user['id']);
                $activeInventoryCosmetics = $storeService->getActiveCosmetics((int) $user['id']);
            } catch (\Throwable $e) {
                $inventoryItems = [];
                $activeInventoryCosmetics = [];
            }
        }

        $activeTab = strtolower((string) $this->get('tab', 'overview'));
        if (!in_array($activeTab, ['overview', 'inventory'], true)) {
            $activeTab = 'overview';
        }
        if (!$isOwnProfile && $activeTab === 'inventory') {
            $activeTab = 'overview';
        }

        $player = [
            'id' => (int) $user['id'],
            'name' => $user['name'],
            'faction_name' => $factionName,
            'points' => (int) $user['points'],
            'premium_balance' => (int) ($user['premium_balance'] ?? 0),
            'rank' => $user['rank'] ?? 0,
            'alliance_id' => $alliance ? (int) $alliance['id'] : 0,
            'alliance_name' => $alliance['name'] ?? '',
            'alliance_tag' => $alliance['name'] ?? '',
            'registered_date' => $user['joined'] ?? '',
            'last_visit' => $user['lastVisit'] ?? '',
            'description' => $user['description'] ?? '',
            'avatar_url' => AvatarHelper::url($avatarStyle, $avatarOptions, 96),
            'town_count' => count($towns),
            'active_badge' => $activeCosmetics['profile_badge']['name'] ?? '',
            'active_title' => $activeCosmetics['profile_title']['name'] ?? '',
            'active_frame_code' => $activeCosmetics['profile_frame']['code'] ?? '',
        ];

        $this->render('profile/view.html', [
            'page_title' => $user['name'],
            'player' => $player,
            'player_towns' => $towns,
            'alliance' => $alliance,
            'is_own_profile' => $isOwnProfile,
            'profile_active_tab' => $activeTab,
            'inventory_items' => $inventoryItems,
            'active_cosmetics' => $activeInventoryCosmetics,
        ]);
    }

    public function showEdit(\Base $f3): void
    {
        $user = $this->currentUser();
        $dbUser = $this->db->exec('SELECT * FROM users WHERE id = ?', [$user['id']]);

        if (empty($dbUser)) {
            $this->flashAndRedirect('User not found.', '/towns');
            return;
        }

        $u = $dbUser[0];
        $avatarOptions = AvatarHelper::decodeAndNormalize($u['avatar_options'] ?? null, $u['avatar_seed'] ?? $u['name'] ?? '');
        $avatarStyle = $u['avatar_style'] ?? AvatarHelper::DEFAULT_STYLE;
        $preferenceService = new PreferenceService($this->db);
        $preferences = $preferenceService->getAll((int) $u['id']);
        $userTowns = $this->db->exec(
            'SELECT id, name FROM towns WHERE owner = ? ORDER BY isCapital DESC, id ASC',
            [(int) $u['id']]
        );
        $blockedUsers = $this->db->exec(
            'SELECT u.id, u.name
             FROM blocklist b
             LEFT JOIN users u ON u.id = b.sender
             WHERE b.recipient = ?
             ORDER BY u.name ASC',
            [(int) $u['id']]
        );

        $this->render('profile/edit.html', [
            'page_title' => $f3->get('lang.editProfile') ?? 'Edit Profile',
            'user' => [
                'id' => (int) $u['id'],
                'name' => $u['name'],
                'email' => $u['email'] ?? '',
                'description' => $u['description'] ?? '',
                'sitter_name' => $u['sitter'] ?? '',
                'avatar_seed' => $u['avatar_seed'] ?? $u['name'] ?? '',
                'avatar_style' => $avatarStyle,
                'avatar_options' => $avatarOptions,
                'avatar_options_json' => json_encode($avatarOptions, JSON_THROW_ON_ERROR),
                'avatar_url' => AvatarHelper::url($avatarStyle, $avatarOptions, 96),
            ],
            'preferences' => $preferences,
            'user_towns' => $userTowns,
            'blocked_users' => $blockedUsers,
        ]);
    }

    public function edit(\Base $f3): void
    {
        if (!$this->requireCsrf('/profile/edit')) return;

        $user = $this->currentUser();
        $description = InputSanitizer::clean($this->post('description', ''));
        $email = InputSanitizer::cleanEmail($this->post('email', ''));
        $avatarOptionsJson = $this->post('avatar_options', '');
        $avatarPayload = json_decode((string) $avatarOptionsJson, true);
        if (!is_array($avatarPayload)) {
            $avatarPayload = [];
        }

        $avatarOptions = AvatarHelper::normalize($avatarPayload, $user['name']);
        $avatarSeed = $avatarOptions['seed'] ?? $user['name'];
        $avatarStyle = $avatarOptions['style'] ?? AvatarHelper::DEFAULT_STYLE;

        if (!empty($email) && InputSanitizer::isValidEmail($email)) {
            $this->db->exec(
                'UPDATE users SET email = ?, description = ?, avatar_seed = ?, avatar_style = ?, avatar_options = ? WHERE id = ?',
                [$email, $description, $avatarSeed, $avatarStyle, json_encode($avatarOptions), $user['id']]
            );
        } else {
            $this->db->exec(
                'UPDATE users SET description = ?, avatar_seed = ?, avatar_style = ?, avatar_options = ? WHERE id = ?',
                [$description, $avatarSeed, $avatarStyle, json_encode($avatarOptions), $user['id']]
            );
        }

        $this->updateSession('avatar_seed', $avatarSeed);
        $this->updateSession('avatar_style', $avatarStyle);
        $this->updateSession('avatar_options', $avatarOptions);
        $this->flashAndRedirect('Profile updated.', '/profile/' . $user['id']);
    }

    public function changePassword(\Base $f3): void
    {
        if (!$this->requireCsrf('/profile/edit')) return;

        $user = $this->currentUser();
        $currentPass = $this->post('current_password', '');
        $newPass = $this->post('new_password', '');
        $confirmPass = $this->post('confirm_password', '');

        if (empty($currentPass) || empty($newPass) || $newPass !== $confirmPass) {
            $this->flashAndRedirect('Please fill in all fields correctly.', '/profile/edit');
            return;
        }

        // Verify current password
        $dbUser = $this->db->exec('SELECT pass FROM users WHERE id = ?', [$user['id']]);

        if (empty($dbUser) || !password_verify($currentPass, $dbUser[0]['pass'])) {
            $this->flashAndRedirect('Current password is incorrect.', '/profile/edit');
            return;
        }

        $newHash = password_hash($newPass, PASSWORD_BCRYPT);
        $this->db->exec('UPDATE users SET pass = ? WHERE id = ?', [$newHash, $user['id']]);

        // Update session
        $this->updateSession('pass_hash', $newHash);

        $this->flashAndRedirect('Password changed.', '/profile/' . $user['id']);
    }

    public function deleteAccount(\Base $f3): void
    {
        if (!$this->requireCsrf('/profile/edit')) return;

        $user = $this->currentUser();

        // Queue for deletion instead of immediate delete
        $this->db->exec(
            'INSERT IGNORE INTO d_queue (user, dueTime) VALUES (?, DATE_ADD(NOW(), INTERVAL 72 HOUR))',
            [$user['id']]
        );

        // Set level to 0 (disabled)
        $this->db->exec('UPDATE users SET level = 0 WHERE id = ?', [$user['id']]);

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_unset();
            session_destroy();
        }

        session_start();
        $this->f3->set('SESSION.flash', 'Account scheduled for deletion.');
        $this->f3->reroute('/login');
    }

    public function setSitter(\Base $f3): void
    {
        if (!$this->requireCsrf('/profile/edit')) return;

        $user = $this->currentUser();
        $sitterName = InputSanitizer::clean($this->post('sitter', ''));

        $this->db->exec('UPDATE users SET sitter = ? WHERE id = ?', [$sitterName, $user['id']]);

        $this->flashAndRedirect('Sitter updated.', '/profile/edit');
    }

    public function setPreferences(\Base $f3): void
    {
        if (!$this->requireCsrf('/profile/edit')) return;

        $user = $this->currentUser();
        $preferenceService = new PreferenceService($this->db);
        $keys = ['allianceReports', 'combatReports', 'tradeReports'];

        foreach ($keys as $key) {
            $raw = $this->post($key, '0');
            $value = in_array((string) $raw, ['1', 'on', 'true', 'yes'], true) ? '1' : '0';
            $preferenceService->set((int) $user['id'], $key, $value);
        }

        $this->flashAndRedirect('Preferences updated.', '/profile/edit');
    }

    public function setNavTown(\Base $f3): void
    {
        if (!$this->requireCsrf('/profile/edit')) return;

        $user   = $this->requireAuth();
        if ($user === null) return;

        $townId = (int) $this->post('town_id', 0);
        if ($townId > 0) {
            // Verify the town belongs to this user
            $row = $this->db->exec(
                'SELECT id FROM towns WHERE id = ? AND owner = ? LIMIT 1',
                [$townId, (int) $user['id']]
            );
            if (!empty($row)) {
                $prefService = new PreferenceService($this->db);
                $prefService->set((int) $user['id'], 'nav_town_id', (string) $townId);
            }
        }

        $this->flashAndRedirect('Navigation town updated.', '/profile/edit');
    }

    public function addBlockUser(\Base $f3): void
    {
        if (!$this->requireCsrf('/profile/edit')) return;

        $user = $this->currentUser();
        $targetName = InputSanitizer::clean($this->post('block_name', ''));
        if ($targetName === '') {
            $this->flashAndRedirect('Please enter a username.', '/profile/edit');
            return;
        }

        $target = $this->db->exec('SELECT id FROM users WHERE name = ? LIMIT 1', [$targetName]);
        if (empty($target)) {
            $this->flashAndRedirect('User not found.', '/profile/edit');
            return;
        }

        $targetId = (int) $target[0]['id'];
        if ($targetId === (int) $user['id']) {
            $this->flashAndRedirect('You cannot block yourself.', '/profile/edit');
            return;
        }

        $this->db->exec(
            'INSERT IGNORE INTO blocklist (recipient, sender) VALUES (?, ?)',
            [(int) $user['id'], $targetId]
        );

        $this->flashAndRedirect('User blocked.', '/profile/edit');
    }

    public function removeBlockUser(\Base $f3): void
    {
        if (!$this->requireCsrf('/profile/edit')) return;

        $user = $this->currentUser();
        $senderId = InputSanitizer::cleanInt($this->post('sender_id', 0));
        if ($senderId <= 0) {
            $this->flashAndRedirect('Invalid user.', '/profile/edit');
            return;
        }

        $this->db->exec(
            'DELETE FROM blocklist WHERE recipient = ? AND sender = ?',
            [(int) $user['id'], $senderId]
        );

        $this->flashAndRedirect('User unblocked.', '/profile/edit');
    }

    public function changeLanguage(\Base $f3, array $params): void
    {
        $code = $params['code'] ?? 'en';

        // Whitelist: only 2-letter lowercase codes
        if (!preg_match('/^[a-z]{2}$/', $code)) {
            $this->flashAndRedirect('Invalid language code.', '/');
            return;
        }

        $langFile = $code . '.php';
        $langPath = $f3->get('ROOT') . '/language/' . $langFile;

        if (!file_exists($langPath)) {
            $this->flashAndRedirect('Language not found.', '/');
            return;
        }

        $f3->set('SESSION.lang', $langFile);

        if ($this->isLoggedIn()) {
            $user = $this->currentUser();
            $this->db->exec('UPDATE users SET lang = ? WHERE id = ?', [$langFile, $user['id']]);
            $this->updateSession('language', $langFile);
        }

        // Prevent open redirect: only allow relative URLs
        $referer = $_SERVER['HTTP_REFERER'] ?? '/';
        if (!str_starts_with($referer, '/') || str_starts_with($referer, '//')) {
            $referer = '/';
        }
        $this->redirect($referer);
    }
}

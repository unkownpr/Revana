<?php declare(strict_types=1);

namespace Devana\Controllers;

use Devana\Helpers\InputSanitizer;
use Devana\Services\AuthService;
use Devana\Services\MailerService;
use Devana\Services\StreakService;

final class AuthController extends Controller
{
    public function showLogin(\Base $f3): void
    {
        if ($this->isLoggedIn()) {
            $this->redirect('/towns');
            return;
        }

        $this->render('auth/login.html', [
            'page_title' => $f3->get('lang.login') ?? 'Login',
        ]);
    }

    public function login(\Base $f3): void
    {
        if (!$this->requireCsrf('/login')) return;
        if (!$this->requireRecaptcha('/login')) return;

        $username = InputSanitizer::clean($this->post('name', ''));
        $password = $this->post('pass', '');

        if (empty($username) || empty($password)) {
            $this->flashAndRedirect(
                $f3->get('lang.noInput') ?? 'Please fill in all fields.',
                '/login'
            );
            return;
        }

        $authService = new AuthService($this->db);

        if ($authService->isLocked($username)) {
            $this->flashAndRedirect(
                $f3->get('lang.accountLocked') ?? 'Too many failed attempts. Try again in 15 minutes.',
                '/login'
            );
            return;
        }

        $user = $authService->authenticate($username, $password);

        if ($user === null) {
            $authService->recordFailedAttempt($username);
            $this->flashAndRedirect(
                $f3->get('lang.noUserWrong') ?? 'Invalid username or password.',
                '/login'
            );
            return;
        }

        $authService->resetFailedAttempts((int) $user['id']);

        // Check if login is allowed
        $config = $this->db->exec('SELECT * FROM config ORDER BY ord ASC');
        $loginAllowed = (bool) ($config[2]['value'] ?? 1);

        if (!$loginAllowed && (int) $user['level'] < 4) {
            $this->flashAndRedirect(
                $f3->get('lang.loginClosed') ?? 'Login is currently disabled.',
                '/login'
            );
            return;
        }

        // Regenerate session ID for security
        session_regenerate_id(true);

        // Store user in session with named keys
        $f3->set('SESSION.user', $this->mapUserSession($user));

        // Update last visit
        $this->db->exec(
            'UPDATE users SET lastVisit=NOW(), ip=? WHERE id=?',
            [$_SERVER['REMOTE_ADDR'] ?? '127.0.0.1', $user['id']]
        );

        // Login streak check
        $streakMsg = '';
        try {
            $streakService = new StreakService($this->db);
            $streakResult = $streakService->checkAndUpdate(
                (int) $user['id'],
                $user['last_login_date'] ?? null
            );
            if ($streakResult['is_new_day'] && $streakResult['reward'] !== null) {
                $streakDay = $streakResult['streak'];
                $rewardKey = $streakResult['reward']['key'] ?? '';
                $rewardLabel = $f3->get('lang.' . $rewardKey) ?? ('+' . $streakResult['reward']['resources'] . ' kaynak');
                $streakMsg = ' | ' . ($f3->get('lang.streakDay') ?? 'Gün serisi') . ': ' . $streakDay . '. ' . ($f3->get('lang.streakReward') ?? 'Ödül') . ': ' . $rewardLabel;
            }
        } catch (\Throwable $e) {
            // non-critical
        }

        $this->flashAndRedirect(
            ($f3->get('lang.welcome') ?? 'Welcome') . ', ' . $user['name'] . '.' . $streakMsg,
            '/towns'
        );
    }

    public function showRegister(\Base $f3): void
    {
        if ($this->isLoggedIn()) {
            $this->redirect('/towns');
            return;
        }

        // Check if registration is open
        $registerAllowed = (bool) ((int) ($f3->get('game.registration_open') ?? 1));

        if (!$registerAllowed) {
            $this->flashAndRedirect(
                $f3->get('lang.regClosed') ?? 'Registration is closed.',
                '/'
            );
            return;
        }

        // Load language before deriving translated faction names.
        $this->loadLanguage();
        $factions = $this->db->exec('SELECT * FROM factions ORDER BY id');
        $lang = (array) ($f3->get('lang') ?? []);
        $factionNameMap = (array) ($lang['faction_names'] ?? []);
        foreach ($factions as &$faction) {
            $id = (int) ($faction['id'] ?? 0);
            $faction['display_name'] = $factionNameMap[$id] ?? $faction['name'];
        }
        unset($faction);

        $this->render('auth/register.html', [
            'page_title' => $f3->get('lang.register') ?? 'Register',
            'factions' => $factions,
        ]);
    }

    public function register(\Base $f3): void
    {
        if (!$this->requireCsrf('/register')) return;
        if (!$this->requireRecaptcha('/register')) return;

        $username = InputSanitizer::clean($this->post('name', ''));
        $password = $this->post('pass', '');
        $passwordConfirm = $this->post('pass_', '');
        $email = InputSanitizer::cleanEmail($this->post('email', ''));
        $factionId = InputSanitizer::cleanInt($this->post('faction', 0)) + 1;

        // Validate inputs
        if (empty($username) || empty($password) || $password !== $passwordConfirm) {
            $this->flashAndRedirect(
                $f3->get('lang.dataFields') ?? 'Please fill in all fields correctly.',
                '/register'
            );
            return;
        }

        if (!InputSanitizer::isValidEmail($email)) {
            $this->flashAndRedirect('Invalid email address.', '/register');
            return;
        }

        // Check if user exists
        $authService = new AuthService($this->db);

        if ($authService->isUserTaken($username, $email, $_SERVER['REMOTE_ADDR'] ?? '')) {
            $this->flashAndRedirect(
                $f3->get('lang.nameTaken') ?? 'Username or email already taken.',
                '/register'
            );
            return;
        }

        // Register
        if ($authService->register($username, $password, $email, $factionId)) {
            $this->flashAndRedirect(
                'Registration successful. You can now login.',
                '/login'
            );
        } else {
            $this->flashAndRedirect('Registration failed. Please try again.', '/register');
        }
    }

    public function showForgot(\Base $f3): void
    {
        $this->render('auth/forgot.html', [
            'page_title' => $f3->get('lang.forgotPassword') ?? 'Forgot Password',
        ]);
    }

    public function forgot(\Base $f3): void
    {
        if (!$this->requireCsrf('/forgot')) return;

        $username = InputSanitizer::clean($this->post('name', ''));

        if (empty($username)) {
            $this->flashAndRedirect('Please enter your username.', '/forgot');
            return;
        }

        $authService = new AuthService($this->db);
        $user = $authService->findByUsername($username);

        if ($user === null || empty($user['email'])) {
            $this->flashAndRedirect('User not found or no email on file.', '/forgot');
            return;
        }

        $mailer = new MailerService($this->db);
        if (!$mailer->isEnabled()) {
            $this->flashAndRedirect('Mail system is disabled on this server. Contact an administrator.', '/forgot');
            return;
        }
        if (!$mailer->isPhpMailerAvailable()) {
            $this->flashAndRedirect('PHPMailer is not installed on this server. Contact an administrator.', '/forgot');
            return;
        }

        // Generate random password
        $newPassword = bin2hex(random_bytes(4)); // 8 character random password
        $hash = password_hash($newPassword, PASSWORD_BCRYPT);
        $authService->resetPassword((int) $user['id'], $hash);

        // Send email
        $gameTitle = $f3->get('game.title') ?? 'Devana';
        $subject = $gameTitle . ' - Password Reset';
        $body = "Your new password is: " . $newPassword . "\n\nPlease change it after logging in.";
        $sendResult = $mailer->send((string) $user['email'], $subject, $body);
        if ($sendResult !== true) {
            $this->flashAndRedirect((string) $sendResult, '/forgot');
            return;
        }

        $this->flashAndRedirect('A new password has been sent to your email.', '/login');
    }

    public function logout(\Base $f3): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_unset();
            session_destroy();
        }

        session_start();
        $f3->set('SESSION.flash', 'You have been logged out.');
        $f3->reroute('/login');
    }

    private function mapUserSession(array $dbRow): array
    {
        $langFile = (string) ($dbRow['lang'] ?? 'en.php');
        if (preg_match('/^[a-z]{2}$/', $langFile)) {
            $langFile .= '.php';
        }
        if (!preg_match('/^[a-z]{2}\.php$/', $langFile)) {
            $langFile = 'en.php';
        }

        return [
            'id' => (int) $dbRow['id'],
            'name' => $dbRow['name'],
            'pass_hash' => $dbRow['pass'],
            'email' => $dbRow['email'],
            'level' => (int) $dbRow['level'],
            'faction' => (int) $dbRow['faction'],
            'alliance' => (int) ($dbRow['alliance'] ?? 0),
            'rank' => (int) ($dbRow['rank'] ?? 0),
            'description' => $dbRow['description'] ?? '',
            'points' => (int) ($dbRow['points'] ?? 0),
            'language' => $langFile,
            'imgs' => 'default/',
            'fimgs' => ($dbRow['grPath'] ?? '1/'),
            'avatar_seed' => $dbRow['avatar_seed'] ?? $dbRow['name'],
            'avatar_style' => $dbRow['avatar_style'] ?? 'pixel-art',
            'avatar_options' => $dbRow['avatar_options'] ?? null,
            'premium_balance' => (int) ($dbRow['premium_balance'] ?? 0),
            'login_streak'    => (int) ($dbRow['login_streak'] ?? 0),
            'last_login_date' => $dbRow['last_login_date'] ?? null,
        ];
    }
}

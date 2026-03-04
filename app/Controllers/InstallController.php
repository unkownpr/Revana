<?php declare(strict_types=1);

namespace Devana\Controllers;

use Devana\Services\InstallService;
use Devana\Services\MigrationService;

final class InstallController extends Controller
{
    private InstallService $installService;

    public function __construct()
    {
        parent::__construct();
        $this->installService = new InstallService();
    }

    /**
     * Step 1: Check system requirements
     */
    public function step1(\Base $f3): void
    {
        $checks = $this->installService->checkRequirements($f3->get('ROOT'));
        $allPassed = $this->installService->allRequirementsPassed($checks);

        $this->renderMinimal('install/step1-requirements.html', [
            'checks' => $checks,
            'all_passed' => $allPassed,
        ]);
    }

    /**
     * Step 2: Database configuration and import
     */
    public function step2(\Base $f3): void
    {
        $host = trim($this->post('db_host', 'localhost'));
        $port = trim($this->post('db_port', '3306'));
        $name = trim($this->post('db_name', 'devana'));
        $user = trim($this->post('db_user', 'root'));
        $pass = $this->post('db_pass', '');
        $gameTitle = trim($this->post('game_title', 'Devana'));

        // Test connection
        $result = $this->installService->testDatabaseConnection($host, $port, $name, $user, $pass);

        if (is_string($result)) {
            $this->renderMinimal('install/step2-database.html', [
                'error' => $result,
                'db_host' => $host,
                'db_port' => $port,
                'db_name' => $name,
                'db_user' => $user,
                'game_title' => $gameTitle,
            ]);
            return;
        }

        $db = $result;

        // Import base SQL schema
        $sqlPath = $f3->get('ROOT') . '/devana.sql';
        if (!$this->installService->importDatabase($db, $sqlPath)) {
            $this->renderMinimal('install/step2-database.html', [
                'error' => 'Failed to import database schema.',
                'db_host' => $host,
                'db_port' => $port,
                'db_name' => $name,
                'db_user' => $user,
                'game_title' => $gameTitle,
            ]);
            return;
        }

        // Import map data
        $mapPath = $f3->get('ROOT') . '/data/map.dat';
        if (file_exists($mapPath)) {
            $this->installService->importMapData($db, $mapPath);
        }

        // Apply runtime migrations so fresh installs match live code schema.
        $migrationService = new MigrationService();
        $migrationResults = $migrationService->applyAll($db);
        foreach ($migrationResults as $result) {
            if (str_starts_with($result, '[FAIL]')) {
                $this->renderMinimal('install/step2-database.html', [
                    'error' => 'Failed to apply migrations: ' . $result,
                    'db_host' => $host,
                    'db_port' => $port,
                    'db_name' => $name,
                    'db_user' => $user,
                    'game_title' => $gameTitle,
                ]);
                return;
            }
        }

        // Write config file
        $this->installService->writeConfig(
            $f3->get('ROOT'),
            $host,
            $port,
            $name,
            $user,
            $pass,
            $gameTitle
        );

        // Store DB in session for step 3
        $f3->set('SESSION.install_db', [
            'host' => $host,
            'port' => $port,
            'name' => $name,
            'user' => $user,
            'pass' => $pass,
        ]);

        // Load factions for step 3
        $factions = $db->exec('SELECT * FROM factions ORDER BY id');

        $this->renderMinimal('install/step3-admin.html', [
            'factions' => $factions,
            'game_title' => $gameTitle,
        ]);
    }

    /**
     * Step 3: Create admin account
     */
    public function step3(\Base $f3): void
    {
        $dbConfig = $f3->get('SESSION.install_db');

        if (!$dbConfig) {
            $this->redirect('/install');
            return;
        }

        $username = trim($this->post('username', ''));
        $password = $this->post('password', '');
        $passwordConfirm = $this->post('password_confirm', '');
        $email = trim($this->post('email', ''));
        $factionId = (int) $this->post('faction', 1);
        $townName = trim($this->post('town_name', 'My Town'));

        // Validation
        $errors = $this->validateAdminInput($username, $password, $passwordConfirm, $email);

        if (!empty($errors)) {
            $db = $this->connectFromSession($dbConfig);
            $factions = $db ? $db->exec('SELECT * FROM factions ORDER BY id') : [];

            $this->renderMinimal('install/step3-admin.html', [
                'errors' => $errors,
                'factions' => $factions,
                'username' => $username,
                'email' => $email,
                'town_name' => $townName,
            ]);
            return;
        }

        $db = $this->connectFromSession($dbConfig);

        if (!$db) {
            $this->redirect('/install');
            return;
        }

        // Create admin account
        if (!$this->installService->createAdminAccount($db, $username, $password, $email, $factionId)) {
            $factions = $db->exec('SELECT * FROM factions ORDER BY id');
            $this->renderMinimal('install/step3-admin.html', [
                'errors' => ['Failed to create admin account.'],
                'factions' => $factions,
                'username' => $username,
                'email' => $email,
                'town_name' => $townName,
            ]);
            return;
        }

        // Get user ID
        $user = $db->exec('SELECT id FROM users WHERE name=?', [$username]);
        $userId = (int) $user[0]['id'];

        // Create first town
        $this->installService->createFirstTown($db, $userId, $factionId, $townName);

        // Create lock file
        $this->installService->createLockFile($f3->get('ROOT'));

        // Clean up session
        $f3->clear('SESSION.install_db');

        $this->renderMinimal('install/step4-complete.html', [
            'username' => $username,
        ]);
    }

    /**
     * Step 4: Redirect after completion
     */
    public function complete(\Base $f3): void
    {
        $this->redirect('/login');
    }

    private function validateAdminInput(
        string $username,
        string $password,
        string $passwordConfirm,
        string $email
    ): array {
        $errors = [];

        if (strlen($username) < 3) {
            $errors[] = 'Username must be at least 3 characters.';
        }

        if (strlen($password) < 6) {
            $errors[] = 'Password must be at least 6 characters.';
        }

        if ($password !== $passwordConfirm) {
            $errors[] = 'Passwords do not match.';
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email address.';
        }

        return $errors;
    }

    private function connectFromSession(array $config): ?\DB\SQL
    {
        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8',
                $config['host'],
                $config['port'],
                $config['name']
            );
            return new \DB\SQL($dsn, $config['user'], $config['pass']);
        } catch (\PDOException $e) {
            return null;
        }
    }
}

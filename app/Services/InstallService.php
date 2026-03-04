<?php declare(strict_types=1);

namespace Devana\Services;

final class InstallService
{
    private const REQUIRED_PHP_VERSION = '8.0.0';
    private const REQUIRED_EXTENSIONS = ['mysqli', 'pdo', 'pdo_mysql', 'session', 'gd', 'mbstring'];

    public function checkRequirements(string $rootPath): array
    {
        $checks = [];

        $checks['php_version'] = [
            'label' => 'PHP Version >= ' . self::REQUIRED_PHP_VERSION,
            'passed' => version_compare(PHP_VERSION, self::REQUIRED_PHP_VERSION, '>='),
            'current' => PHP_VERSION,
        ];

        foreach (self::REQUIRED_EXTENSIONS as $ext) {
            $checks['ext_' . $ext] = [
                'label' => "PHP Extension: {$ext}",
                'passed' => extension_loaded($ext),
                'current' => extension_loaded($ext) ? 'Loaded' : 'Missing',
            ];
        }

        $checks['tmp_writable'] = [
            'label' => 'tmp/ directory writable',
            'passed' => is_writable($rootPath . '/tmp'),
            'current' => is_writable($rootPath . '/tmp') ? 'Writable' : 'Not writable',
        ];

        $checks['sql_exists'] = [
            'label' => 'devana.sql exists',
            'passed' => file_exists($rootPath . '/devana.sql'),
            'current' => file_exists($rootPath . '/devana.sql') ? 'Found' : 'Missing',
        ];

        return $checks;
    }

    public function allRequirementsPassed(array $checks): bool
    {
        foreach ($checks as $check) {
            if (!$check['passed']) {
                return false;
            }
        }

        return true;
    }

    public function testDatabaseConnection(
        string $host,
        string $port,
        string $name,
        string $user,
        string $pass
    ): \DB\SQL|string {
        try {
            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8', $host, $port, $name);
            return new \DB\SQL($dsn, $user, $pass);
        } catch (\PDOException $e) {
            return $e->getMessage();
        }
    }

    public function importDatabase(\DB\SQL $db, string $sqlFilePath): bool
    {
        if (!file_exists($sqlFilePath)) {
            return false;
        }

        $sql = file_get_contents($sqlFilePath);

        if ($sql === false) {
            return false;
        }

        // Remove comments and split by semicolons
        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
        $sql = preg_replace('/--.*$/m', '', $sql);
        $statements = array_filter(
            array_map('trim', explode(';', $sql)),
            fn(string $s) => !empty($s)
        );

        foreach ($statements as $statement) {
            try {
                $db->exec($statement);
            } catch (\PDOException $e) {
                // Skip errors for IF NOT EXISTS statements
                if (!str_contains($statement, 'IF NOT EXISTS')) {
                    return false;
                }
            }
        }

        return true;
    }

    public function importMapData(\DB\SQL $db, string $mapFilePath): bool
    {
        if (!file_exists($mapFilePath)) {
            return false;
        }

        $data = file_get_contents($mapFilePath);

        if ($data === false) {
            return false;
        }

        $lines = array_filter(explode("\n", trim($data)));

        foreach ($lines as $line) {
            $parts = explode(',', trim($line));

            if (count($parts) >= 4) {
                $db->exec(
                    'INSERT IGNORE INTO map (x, y, type, subtype) VALUES (?, ?, ?, ?)',
                    [(int) $parts[0], (int) $parts[1], (int) $parts[2], (int) $parts[3]]
                );
            }
        }

        return true;
    }

    public function writeConfig(
        string $rootPath,
        string $host,
        string $port,
        string $name,
        string $user,
        string $pass,
        string $gameTitle
    ): bool {
        $config = <<<INI
        [globals]
        ; Application
        DEBUG=0
        AUTOLOAD=app/
        UI=ui/
        TEMP=tmp/
        LOGS=tmp/logs/
        CACHE=true

        ; Database
        db.host={$host}
        db.port={$port}
        db.name={$name}
        db.user={$user}
        db.pass={$pass}

        ; Session
        JAR.expire=0
        JAR.path=/
        JAR.secure=false
        JAR.httponly=true

        ; Game Settings
        game.title={$gameTitle}
        game.map_size=49
        INI;

        // Remove leading whitespace from heredoc indentation
        $config = preg_replace('/^ {8}/m', '', $config);

        return (bool) file_put_contents($rootPath . '/config.ini', $config);
    }

    public function createAdminAccount(
        \DB\SQL $db,
        string $username,
        string $password,
        string $email,
        int $factionId
    ): bool {
        $passwordHash = password_hash($password, PASSWORD_BCRYPT);
        $defaultLang = 'en.php';
        $langRow = $db->exec("SELECT value FROM config WHERE name = 'default_lang' ORDER BY ord ASC LIMIT 1");
        if (!empty($langRow)) {
            $candidate = (string) ($langRow[0]['value'] ?? 'en.php');
            if (preg_match('/^[a-z]{2}\.php$/', $candidate)) {
                $defaultLang = $candidate;
            }
        }

        try {
            $db->exec(
                'INSERT INTO users (name, pass, email, level, faction, alliance, `rank`, sitter, description, points, ip, joined, lastVisit, mute, lang, grPath)
                 VALUES (?, ?, ?, 5, ?, 0, 0, \'\', \'\', 0, ?, CURDATE(), NOW(), 0, ?, ?)',
                [$username, $passwordHash, $email, $factionId, $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1', $defaultLang, $factionId . '/']
            );

            return true;
        } catch (\PDOException $e) {
            return false;
        }
    }

    public function createFirstTown(\DB\SQL $db, int $userId, int $factionId, string $townName): bool
    {
        try {
            // Insert town with default values
            $db->exec(
                'INSERT INTO towns (owner, name, population, isCapital, morale, weapons, army, buildings,
                 production, resources, limits, upkeep, land, description, general, water, uUpgrades, wUpgrades, aUpgrades, lastCheck)
                 VALUES (?, ?, 2, 1, 100,
                 \'0-0-0-0-0-0-0-0-0-0-0\',
                 \'0-0-0-0-0-0-0-0-0-0-0-0-0\',
                 \'0-0-0-0-0-0-0-1-1-0-0-0-0-0-0-0-0-0-0-0-0-0\',
                 \'20-10-10-10-10\',
                 \'500-500-500-500-500\',
                 \'800-600-500\',
                 2,
                 \'0-0-0-0/0-0-0-0/0-0-0-0/0-0-0-0\',
                 \'\',
                 \'0-0-0\',
                 -1,
                 \'0-0-0-0-0-0-0-0-0-0-0-0-0\',
                 \'0-0-0-0-0-0-0-0-0-0-0\',
                 \'0-0-0\',
                 NOW())',
                [$userId, $townName]
            );

            $townId = $db->lastInsertId();

            // Place town on map (find first available land tile)
            $result = $db->exec(
                'SELECT x, y FROM map WHERE type=1 AND subtype!=0 LIMIT 1'
            );

            if (!empty($result)) {
                $db->exec(
                    'UPDATE map SET type=3, subtype=? WHERE x=? AND y=?',
                    [$townId, $result[0]['x'], $result[0]['y']]
                );
            }

            return true;
        } catch (\PDOException $e) {
            return false;
        }
    }

    public function createLockFile(string $rootPath): bool
    {
        return (bool) file_put_contents(
            $rootPath . '/install.lock',
            'Installed on ' . date('Y-m-d H:i:s')
        );
    }
}

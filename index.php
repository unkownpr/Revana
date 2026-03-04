<?php declare(strict_types=1);

// Serve static files directly when using PHP built-in server
if (php_sapi_name() === 'cli-server') {
    $requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $filePath = __DIR__ . $requestUri;
    if ($requestUri !== '/' && file_exists($filePath) && is_file($filePath)) {
        return false;
    }
}

require __DIR__ . '/vendor/autoload.php';

$f3 = \Base::instance();

// Load configuration
$configFile = __DIR__ . '/config.ini';
if (file_exists($configFile)) {
    $f3->config($configFile);
}

// Core settings
$f3->set('AUTOLOAD', __DIR__ . '/app/');
$f3->set('UI', __DIR__ . '/ui/');
$f3->set('TEMP', __DIR__ . '/tmp/');
$f3->set('LOGS', __DIR__ . '/tmp/logs/');
$f3->set('ROOT', __DIR__);
$f3->set('FALLBACK', 'en');
$f3->set('ENCODING', 'UTF-8');
$f3->set('CACHE', true);

// Session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database connection (only if config exists with DB settings)
if ($f3->exists('db.host') && $f3->get('db.host')) {
    try {
        $db = new \DB\SQL(
            sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8',
                $f3->get('db.host'),
                $f3->get('db.port') ?: '3306',
                $f3->get('db.name')
            ),
            $f3->get('db.user'),
            $f3->get('db.pass')
        );
        $f3->set('DB', $db);
    } catch (\PDOException $e) {
        if (!str_starts_with($f3->get('PATH') ?? '/', '/install')) {
            $f3->error(500, 'Database connection failed');
        }
    }
}

// Load routes
$f3->config(__DIR__ . '/routes.ini');

// Install check: redirect to /install if not installed
$isInstalled = file_exists(__DIR__ . '/install.lock');
$path = $f3->get('PATH') ?? '/';
$isInstallRoute = str_starts_with($path, '/install');
$isAssetRequest = (bool) preg_match('/\.(css|js|png|jpg|gif|ico|swf|svg|woff|ttf)$/i', $path);

if (!$isInstalled && !$isInstallRoute && !$isAssetRequest) {
    $f3->reroute('/install');
}

if ($isInstalled && $isInstallRoute) {
    $f3->reroute('/');
}

// Centralized route security checks.
$path = $f3->get('PATH') ?? '/';
$verb = strtoupper((string) ($f3->get('VERB') ?? 'GET'));

$requiresAdmin = static function (string $routePath): bool {
    return (bool) preg_match('#^/admin(?:/|$)#', $routePath);
};

$requiresAuthGet = static function (string $routePath): bool {
    if ($routePath === '/towns') {
        return true;
    }

    if (preg_match('#^/town(?:/|$)#', $routePath)) return true;
    if (preg_match('#^/alliance(?:/|$)#', $routePath)) return true;
    if (preg_match('#^/messages(?:/|$)#', $routePath)) return true;
    if (preg_match('#^/message(?:/|$)#', $routePath)) return true;
    if (preg_match('#^/reports(?:/|$)#', $routePath)) return true;
    if (preg_match('#^/report(?:/|$)#', $routePath)) return true;
    if (preg_match('#^/forum(?:/|$)#', $routePath)) return true;
    if (preg_match('#^/chat(?:/|$)#', $routePath)) return true;
    if (preg_match('#^/premium(?:/|$)#', $routePath)) return true;
    if (preg_match('#^/profile/(edit|password|delete|sitter)$#', $routePath)) return true;
    if (preg_match('#^/map/acquire$#', $routePath)) return true;
    if (preg_match('#^/map/settle$#', $routePath)) return true;
    if (preg_match('#^/map/[^/]+/[^/]+/settle$#', $routePath)) return true;

    return false;
};

$isPublicPost = static function (string $routePath): bool {
    return (bool) preg_match('#^/(login|register|forgot)$#', $routePath);
};

$skipCsrf = static function (string $routePath): bool {
    // Installer templates do not include CSRF inputs.
    return (bool) preg_match('#^/install(?:/|$)#', $routePath);
};

if ($requiresAdmin($path)) {
    (new \Devana\Middleware\AdminMiddleware())->beforeroute($f3);
} elseif ($verb === 'POST') {
    if (!$skipCsrf($path) && !$isPublicPost($path)) {
        (new \Devana\Middleware\AuthMiddleware())->beforeroute($f3);
    }
} elseif ($requiresAuthGet($path)) {
    (new \Devana\Middleware\AuthMiddleware())->beforeroute($f3);
}

if ($verb === 'POST' && !$skipCsrf($path)) {
    (new \Devana\Middleware\CsrfMiddleware())->beforeroute($f3);
}

// Error handler
$f3->set('ONERROR', function (\Base $f3) {
    $code = $f3->get('ERROR.code');
    $message = $f3->get('ERROR.text');

    if ($f3->get('AJAX')) {
        header('Content-Type: application/json');
        echo json_encode(['error' => $message, 'code' => $code]);
        return;
    }

    // Load language for error page as well (it is outside controller render flow).
    $langFile = 'en.php';
    if ($f3->exists('SESSION.user')) {
        $user = $f3->get('SESSION.user');
        $langFile = $user['language'] ?? 'en.php';
    } elseif ($f3->exists('SESSION.lang')) {
        $langFile = $f3->get('SESSION.lang');
    }
    $langFile = basename((string) $langFile);
    if (!preg_match('/^[a-z]{2}\.php$/', $langFile)) {
        $langFile = 'en.php';
    }

    $root = rtrim((string) $f3->get('ROOT'), '/');
    $enPath = $root . '/language/en.php';
    $langPath = $root . '/language/' . $langFile;
    $loadLang = static function (string $path): array {
        if (!is_file($path)) {
            return [];
        }
        $lang = [];
        require $path;
        return is_array($lang) ? $lang : [];
    };
    $lang = $loadLang($enPath);
    if ($langFile !== 'en.php') {
        $lang = array_replace($lang, $loadLang($langPath));
    }
    $lang = array_replace([
        'backToHome' => 'Back to Home',
    ], $lang);

    $f3->set('lang', $lang);
    $f3->set('error_code', $code);
    $f3->set('error_message', $message);
    echo \Template::instance()->render('shared/error.html');
});

$f3->run();

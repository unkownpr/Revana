#!/usr/bin/env php
<?php declare(strict_types=1);

use Devana\Services\BotService;
use Devana\Services\GameTickService;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$f3 = \Base::instance();
$f3->set('ROOT', $root);
$f3->set('AUTOLOAD', $root . '/app/');
$f3->set('UI', $root . '/ui/');
$f3->set('TEMP', $root . '/tmp/');
$f3->set('LOGS', $root . '/tmp/logs/');

$stdout = @fopen('php://stdout', 'wb');
$stderr = @fopen('php://stderr', 'wb');
$writeOut = static function (string $message) use ($stdout): void {
    if (is_resource($stdout)) {
        fwrite($stdout, $message);
        return;
    }
    error_log(rtrim($message));
};
$writeErr = static function (string $message) use ($stderr): void {
    if (is_resource($stderr)) {
        fwrite($stderr, $message);
        return;
    }
    error_log(rtrim($message));
};
$log = static function (string $message) use ($writeOut): void {
    $writeOut(sprintf("[%s] %s\n", date('Y-m-d H:i:s'), $message));
};

$only = 'all';
$forceCleanup = false;
foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
    if (str_starts_with($arg, '--only=')) {
        $value = strtolower((string) substr($arg, 7));
        if (in_array($value, ['all', 'tick', 'bot', 'cleanup'], true)) {
            $only = $value;
        }
        continue;
    }
    if ($arg === '--cleanup') {
        $forceCleanup = true;
    }
}

$configFile = $root . '/config.ini';
if (!is_file($configFile)) {
    $writeErr("config.ini not found\n");
    exit(1);
}
$f3->config($configFile);

if (!$f3->exists('db.host') || !$f3->get('db.host')) {
    $writeErr("Database config missing\n");
    exit(1);
}

$db = new \DB\SQL(
    sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8',
        $f3->get('db.host'),
        $f3->get('db.port') ?: '3306',
        $f3->get('db.name')
    ),
    (string) $f3->get('db.user'),
    (string) $f3->get('db.pass')
);

$lockFile = $root . '/tmp/cron.lock';
$lockDir  = dirname($lockFile);
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0775, true);
}
$lockFp = fopen($lockFile, 'c');
if ($lockFp === false) {
    $writeErr("Cannot open lock file\n");
    exit(1);
}
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    $writeOut("cron already running, skipping\n");
    fclose($lockFp);
    exit(0);
}

$cleanupNow = ($only === 'cleanup') || $forceCleanup;
if ($only === 'all' && !$cleanupNow) {
    $cleanupNow = (date('H:i') === '03:00');
}

$exitCode = 0;

$runCleanup = static function (\DB\SQL $db, string $rootDir): array {
    $stats = [];

    $db->exec("DELETE FROM chat WHERE timeStamp < NOW() - INTERVAL 3 DAY");
    $stats['chat_rows'] = $db->count();

    $db->exec("DELETE FROM reports WHERE sent < NOW() - INTERVAL 30 DAY");
    $stats['report_rows'] = $db->count();

    $cacheDeleted = 0;
    foreach (glob($rootDir . '/tmp/*.php') ?: [] as $file) {
        if (is_file($file) && @unlink($file)) {
            $cacheDeleted++;
        }
    }
    $stats['cache_files'] = $cacheDeleted;

    $logsDeleted = 0;
    $logsDir = $rootDir . '/tmp/logs';
    if (is_dir($logsDir)) {
        $cutoff = time() - (7 * 86400);
        foreach (glob($logsDir . '/*') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < $cutoff && @unlink($file)) {
                $logsDeleted++;
            }
        }
    }
    $stats['log_files'] = $logsDeleted;

    return $stats;
};

try {
    if ($only === 'all' || $only === 'tick') {
        $tick = new GameTickService($db);
        $resourcesProcessed = $tick->tickResources(250, 8);
        $tick->tickDueQueues(400);
        $log('tick ok resources=' . $resourcesProcessed);
    }

    if ($only === 'all' || $only === 'bot') {
        $botService = new BotService($db);
        $botTicked = $botService->tick();
        $log('bot ok bots_ticked=' . $botTicked);
    }

    if ($cleanupNow) {
        $stats = $runCleanup($db, $root);
        $log(sprintf(
            'cleanup ok chat=%d reports=%d cache_files=%d log_files=%d',
            (int) ($stats['chat_rows'] ?? 0),
            (int) ($stats['report_rows'] ?? 0),
            (int) ($stats['cache_files'] ?? 0),
            (int) ($stats['log_files'] ?? 0)
        ));
    }
} catch (\Throwable $e) {
    $writeErr("cron failed: " . $e->getMessage() . "\n");
    $exitCode = 1;
} finally {
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
}

exit($exitCode);

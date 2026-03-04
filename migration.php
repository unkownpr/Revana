<?php declare(strict_types=1);

/**
 * Database migration script.
 * Run: php migration.php
 */

require __DIR__ . '/vendor/autoload.php';

$f3 = \Base::instance();
$f3->config(__DIR__ . '/config.ini');

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

$migrationService = new \Devana\Services\MigrationService();
$results = $migrationService->applyAll($db);

foreach ($results as $line) {
    echo $line . PHP_EOL;
}

echo "Done.\n";

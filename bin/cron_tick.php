#!/usr/bin/env php
<?php declare(strict_types=1);

// Backward-compatible wrapper. Use bin/cron.php directly.
$_SERVER['argv'] = [__FILE__, '--only=tick'];
require __DIR__ . '/cron.php';

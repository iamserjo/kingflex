<?php

declare(strict_types=1);

/**
 * PHPUnit/Pest bootstrap.
 *
 * We force the testing environment *before* Laravel boots so tests never
 * accidentally run against the dev database (Docker exports DB_* env vars).
 */

require __DIR__.'/../vendor/autoload.php';

$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'testing';
putenv('APP_ENV=testing');

$_SERVER['DB_CONNECTION'] = $_ENV['DB_CONNECTION'] = 'pgsql';
putenv('DB_CONNECTION=pgsql');

// Allow overriding via env if needed, but default to a dedicated test DB.
$testingDatabase = getenv('DB_DATABASE_TESTING') ?: 'marketking_testing';
$_SERVER['DB_DATABASE'] = $_ENV['DB_DATABASE'] = $testingDatabase;
putenv("DB_DATABASE={$testingDatabase}");


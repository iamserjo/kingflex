<?php

namespace Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! app()->environment('testing')) {
            return;
        }

        // Feature tests shouldn't need to manage browser/session middleware (CSRF, etc.).
        // Some tests call Artisan inside beforeEach, which can re-bootstrap parts of the app;
        // disabling all middleware here keeps tests deterministic.
        $this->withoutMiddleware();

        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        $isSafeSqlite = $connection === 'sqlite'
            && ($database === ':memory:' || str_contains($database, 'database.sqlite'));

        $isSafeTestingDbName = $database !== ''
            && (str_ends_with($database, '_testing') || $database === 'testing');

        $isSafePgsql = $connection === 'pgsql' && $isSafeTestingDbName;
        $isSafeMysql = in_array($connection, ['mysql', 'mariadb'], true) && $isSafeTestingDbName;

        if ($isSafeSqlite || $isSafePgsql || $isSafeMysql) {
            return;
        }

        throw new RuntimeException(
            "Refusing to run tests against a non-testing database [{$connection}:{$database}]. ".
            "Update phpunit.xml (or your env) so tests use an isolated DB like *_testing."
        );
    }
}

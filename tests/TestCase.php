<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! app()->environment('testing')) {
            return;
        }

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

<?php

namespace Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Refuse to run against a non-test database, because RefreshDatabase
     * drops every table in the configured database.
     */
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        $connection = $app['config']->get('database.default');
        $database = (string) $app['config']->get("database.connections.{$connection}.database");

        if (! str_ends_with($database, '_test') && $database !== ':memory:') {
            throw new RuntimeException(
                "Refusing to run tests against database [{$database}]. Use a database ending in _test."
            );
        }

        return $app;
    }

    /**
     * actingAs() without a guard signs the user in on both the site and the
     * admin area (separate guards, see UseAdminSession), so a test can reach
     * either without caring which session a route uses.
     */
    public function be(Authenticatable $user, $guard = null)
    {
        if ($guard === null) {
            $this->app['auth']->guard('admin')->setUser($user);
            $guard = 'web';
        }

        return parent::be($user, $guard);
    }

    /** Signs out of the site and the admin area alike. */
    protected function signOut(): void
    {
        $this->app['auth']->forgetGuards();
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Page tests must not depend on a built Vite manifest.
        $this->withoutVite();
    }
}

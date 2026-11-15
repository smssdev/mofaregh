<?php

namespace Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Requests come from the SPA's own origin, so Sanctum treats them as stateful (session + CSRF),
        // exactly like the browser does.
        $this->withHeader('Referer', 'http://localhost/');
    }

    /**
     * Guards cache the user they resolved; a test that switches users between requests needs fresh guards,
     * as every real request gets.
     */
    public function actingAs(Authenticatable $user, $guard = null)
    {
        $this->app['auth']->forgetGuards();

        return parent::actingAs($user, $guard);
    }
}

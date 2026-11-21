<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Ensure tests never boot against a cached config or persistent SQLite database.
     */
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        if (
            $app['config']->get('database.default') === 'sqlite'
            && $app['config']->get('database.connections.sqlite.database') !== ':memory:'
        ) {
            throw new RuntimeException('Tests must use the :memory: SQLite database, not a persistent database file.');
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(PreventRequestForgery::class);
    }
}

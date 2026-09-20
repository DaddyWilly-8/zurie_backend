<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Hard stop: RefreshDatabase would wipe whatever DB is configured.
        if (config('database.connections.mysql.database') !== 'zurie_test') {
            $this->fail('Refusing to run: tests must use the zurie_test database.');
        }
    }
}

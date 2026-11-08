<?php

namespace Tests;

use App\Services\ServerStorage;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The storage banner on every <x-layout> page reads the disk of `/`; without this a page render would change
        // with the disk of the machine running the tests (a full disk shows the banner). The spec 242 tests bind
        // their own fake numbers.
        $this->app->instance(ServerStorage::class, new class extends ServerStorage {
            public function disk(): ?array
            {
                return null;
            }
        });
    }
}

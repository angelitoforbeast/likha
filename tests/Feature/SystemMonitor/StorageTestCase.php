<?php

namespace Tests\Feature\SystemMonitor;

use App\Http\Middleware\AllowedIpMiddleware;
use App\Models\User;
use App\Services\ServerStorage;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Base of the spec 242 tests (sqlite in memory): users and employee profiles made by hand, and a fake ServerStorage
 * bound in the container so no test depends on this machine's disk or on MySQL.
 */
abstract class StorageTestCase extends TestCase
{
    protected const GB = 1024 ** 3;

    /** The fake bound in the container; tests set its numbers and read its call counts. */
    protected FakeServerStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(AllowedIpMiddleware::class);

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->timestamp('email_verified_at')->nullable();
            $t->string('password');
            $t->rememberToken();
            $t->timestamps();
        });
        Schema::create('employee_profiles', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('name')->nullable();
            $t->string('employee_code')->nullable();
            $t->string('role')->nullable();
            $t->string('status')->nullable();
            $t->timestamps();
        });
        Schema::create('tasks', function (Blueprint $t) { // counted by the view composer
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('status')->nullable();
            $t->timestamps();
        });

        $this->storage = new FakeServerStorage();
        $this->app->instance(ServerStorage::class, $this->storage);
    }

    protected function user(?string $role, ?string $email = null): User
    {
        $user = User::create(['name' => 'Test User', 'email' => $email ?? uniqid('u') . '@example.test', 'password' => 'secret-password']);
        $user->employeeProfile()->update(['role' => $role]);

        return $user->fresh();
    }

    /** Disk of `/` with the given used percent of a 200 GB disk. */
    protected function diskAt(float $percent, float $totalGb = 200): void
    {
        $total = $totalGb * self::GB;
        $free  = $total * (100 - $percent) / 100;
        $this->storage->disk = ['total' => $total, 'free' => $free, 'used' => $total - $free, 'percent' => $percent];
    }
}

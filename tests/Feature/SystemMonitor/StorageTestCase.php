<?php

namespace Tests\Feature\SystemMonitor;

use App\Http\Middleware\AllowedIpMiddleware;
use App\Models\User;
use App\Services\ServerStorage;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Base of the spec 242 and 243 tests (sqlite in memory): users and employee profiles made by hand, the nav tables with
 * the Storage link as the spec 243 migration adds it (access follows /owner/nav-settings), and a fake ServerStorage
 * bound in the container so no test depends on this machine's disk or on MySQL.
 */
abstract class StorageTestCase extends TestCase
{
    protected const GB = 1024 ** 3;

    protected const NAV_MIGRATION = 'database/migrations/2026_05_18_140000_create_nav_links_and_visibility_tables.php';

    protected const STORAGE_LINK_MIGRATION = 'database/migrations/2026_11_19_100000_add_storage_nav_link.php';

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

        // The Storage link starts checked for CEO and Marketing - OIC, unchecked for the other roles.
        Artisan::call('migrate', ['--path' => [self::NAV_MIGRATION, self::STORAGE_LINK_MIGRATION], '--force' => true]);

        $this->storage = new FakeServerStorage();
        $this->app->instance(ServerStorage::class, $this->storage);
    }

    /** Check or uncheck the Storage link for a role, as the nav settings checkbox stores it. */
    protected function setStorageAccess(string $role, bool $visible): void
    {
        $linkId = DB::table('nav_links')->where('key', 'storage')->value('id');
        DB::table('nav_link_role_visibility')->updateOrInsert(
            ['nav_link_id' => $linkId, 'role' => $role],
            ['is_visible' => $visible, 'created_at' => now(), 'updated_at' => now()],
        );
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

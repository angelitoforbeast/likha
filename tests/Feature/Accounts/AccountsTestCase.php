<?php

namespace Tests\Feature\Accounts;

use App\Http\Middleware\AllowedIpMiddleware;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Base of the spec 298 tests (sqlite in memory). The users table, its password_plain and created_by columns and every
 * nav-link migration are the real ones, so the pages render in the real layout; employee_profiles and the two task
 * tables (read by the layout's view composer and the login listeners) are made by hand, as the other test bases do.
 * Needs nothing from spec 295 beyond what its own migrations bring, so it runs on a line without that nav too.
 */
abstract class AccountsTestCase extends TestCase
{
    protected const PASSWORD = 'secret-password';

    protected function setUp(): void
    {
        parent::setUp();

        // allowed_ip: not what these tests check (as OwnerPasswordChangeTest).
        $this->withoutMiddleware(AllowedIpMiddleware::class);

        Schema::create('employee_profiles', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('name')->nullable();
            $t->string('employee_code')->nullable();
            $t->string('role')->nullable();
            $t->string('employment_type')->nullable();
            $t->string('status')->nullable();
            $t->timestamps();
        });
        Schema::create('tasks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('task_name')->nullable();
            $t->date('due_date')->nullable();
            $t->string('status')->nullable();
            $t->timestamps();
        });
        Schema::create('everyday_tasks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('task_name')->nullable();
            $t->timestamps();
        });

        $paths = [
            'database/migrations/0001_01_01_000000_create_users_table.php',
            'database/migrations/2026_05_20_160000_add_password_plain_to_users.php',
            'database/migrations/2026_11_29_298000_add_created_by_to_users.php',
        ];
        foreach (glob(base_path('database/migrations/*nav*')) as $file) {
            $paths[] = 'database/migrations/' . basename($file);
        }
        sort($paths);
        Artisan::call('migrate', ['--path' => $paths, '--force' => true]);
    }

    protected function user(?string $role, string $name = 'Test User', ?string $email = null): User
    {
        $user = User::create([
            'name'     => $name,
            'email'    => $email ?? uniqid('u') . '@example.test',
            'password' => self::PASSWORD,
        ]);
        $user->employeeProfile()->update(['role' => $role]);

        return $user->fresh();
    }

    /** A valid form for /accounts, with overrides. */
    protected function form(array $overrides = []): array
    {
        return array_merge([
            'name'                  => 'Nina Santos',
            'email'                 => 'nina@example.test',
            'role'                  => 'Marketing',
            'password'              => 'nina-pass-1',
            'password_confirmation' => 'nina-pass-1',
        ], $overrides);
    }

    protected function userCount(): int
    {
        return DB::table('users')->count();
    }

    protected function profileCount(): int
    {
        return DB::table('employee_profiles')->count();
    }
}

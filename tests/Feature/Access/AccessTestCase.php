<?php

namespace Tests\Feature\Access;

use App\Http\Middleware\AllowedIpMiddleware;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Base of the access tests (spec 294, sqlite in-memory): users and profiles by hand as the other test cases do,
 * then the real migrations for app_settings, the seeded nav links and access_records.
 */
abstract class AccessTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // allowed_ip is not what these tests are about (as in the staff activity tests).
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
            $t->string('department')->nullable();
            $t->string('status')->nullable();
            $t->timestamps();
        });
        // Counted by the layout (pending tasks in the nav) on every page.
        Schema::create('tasks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('status')->nullable();
            $t->timestamps();
        });
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('guard_name')->default('web');
            $t->integer('access_level')->nullable();
            $t->timestamps();
        });

        foreach (self::MIGRATIONS as $file) {
            (require database_path("migrations/$file"))->up();
        }
    }

    private const MIGRATIONS = [
        '2026_04_10_000003_create_app_settings_table.php',
        '2026_05_18_140000_create_nav_links_and_visibility_tables.php',
        '2026_06_04_030000_add_supply_finance_nav_link.php',
        '2026_11_27_294000_create_access_records_table.php',
    ];

    /** A signed-in staff member; null = a profile without a role, false = no profile at all. */
    protected function staff(string|null|false $role = 'Marketing'): User
    {
        static $n = 0;
        $n++;
        $user = User::create(['name' => "Staff $n", 'email' => "access{$n}@example.test", 'password' => 'secret-password']);
        if ($role === false) {
            $user->employeeProfile()->delete();
        } else {
            $user->employeeProfile()->update(['role' => $role]);
        }

        return $user->fresh();
    }
}

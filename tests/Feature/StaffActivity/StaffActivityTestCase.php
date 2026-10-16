<?php

namespace Tests\Feature\StaffActivity;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Base ng staff activity tests (sqlite in-memory): gawa sa kamay ang users at employee_profiles
 * (gaya ng ibang test case), tapos ang sariling migrations ng staff activity ang nagdadagdag ng
 * last_seen / last_login columns at ng staff_events table.
 */
abstract class StaffActivityTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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
            $t->string('phone')->nullable();
            $t->timestamps();
        });
        // Binabasa ng login listener na CopyEverydayTasksOnLogin.
        Schema::create('everyday_tasks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('task_name')->nullable();
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

        foreach (self::MIGRATIONS as $file) {
            (require database_path("migrations/$file"))->up();
        }
    }

    private const MIGRATIONS = [
        '2026_10_16_100001_add_last_seen_and_login_to_users.php',
        '2026_10_16_100002_create_staff_events_table.php',
        '2026_10_16_200001_add_source_to_staff_events.php',
    ];

    protected function staff(string $role = 'Data Encoder', string $name = 'Staff', ?string $department = null): User
    {
        static $n = 0;
        $n++;
        $user = User::create(['name' => "$name $n", 'email' => "staff{$n}@example.test", 'password' => 'secret-password']);
        $user->employeeProfile()->update(['role' => $role, 'department' => $department]);

        return $user->fresh();
    }

    protected function events()
    {
        return DB::table('staff_events')->orderBy('id')->get();
    }
}

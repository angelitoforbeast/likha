<?php

namespace Tests\Feature\OwnerPrivate;

use App\Http\Middleware\AllowedIpMiddleware;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Base ng mga /owner/private test (sqlite in-memory), gaya ng ItemTestCase.
 * users / employee_profiles = gawa sa kamay; ang Action note tables at app_settings = totoong migrations.
 */
abstract class OwnerPrivateTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // allowed_ip: hindi ito ang sinusubok dito.
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

        Artisan::call('migrate', [
            '--path'  => $this->migrationPaths(),
            '--force' => true,
        ]);
    }

    /** Totoong migrations ng mga table na ginagamit ng /owner/private tests. */
    protected function migrationPaths(): array
    {
        return [
            'database/migrations/2026_04_10_000003_create_app_settings_table.php',
            'database/migrations/2026_06_03_010000_create_page_day_actions_table.php',
            'database/migrations/2026_06_03_010001_create_page_day_action_logs_table.php',
            'database/migrations/2026_10_04_110000_create_page_day_claude_actions_table.php',
            'database/migrations/2026_10_04_110001_create_page_day_claude_action_logs_table.php',
        ];
    }

    protected function user(string $role = 'CEO', string $email = 'ceo@example.test'): User
    {
        $user = User::create(['name' => $role . ' User', 'email' => $email, 'password' => 'secret-password']);
        $user->employeeProfile()->update(['role' => $role]);

        return $user->fresh();
    }
}

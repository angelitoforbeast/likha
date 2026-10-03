<?php

namespace Tests\Feature\NightRun;

use App\Http\Middleware\AllowedIpMiddleware;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Base ng mga night-run test (sqlite in-memory), gaya ng ItemTestCase.
 *
 * users / employee_profiles = minimal na legacy columns lang. Ang import tables, app_settings
 * at jobs = totoong migrations (tumatakbo sila sa sqlite), kaya kapareho ng production ang columns.
 * Ang cache store sa test ay `array` (may lock sa loob ng iisang process) — walang cache table.
 * Dadagdagan ito ng mga susunod na task (macro_output, ai_checker_logs, night tables).
 */
abstract class NightRunTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // allowed_ip: CEO exempted; ang ibang role ay hinaharang sa 127.0.0.1 — hindi ito ang sinusubok dito.
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

    /** Totoong migrations ng mga table na ginagamit ng night run. */
    protected function migrationPaths(): array
    {
        return [
            'database/migrations/0001_01_01_000002_create_jobs_table.php',
            'database/migrations/2025_06_26_102848_create_likha_order_settings_table.php',
            'database/migrations/2025_07_11_111227_create_macro_gsheet_settings_table.php',
            'database/migrations/2025_07_12_183927_add_gsheet_name_to_macro_gsheet_settings_table.php',
            'database/migrations/2026_01_06_212129_create_likha_import_runs_table.php',
            'database/migrations/2026_01_06_212201_create_likha_import_run_sheets_table.php',
            'database/migrations/2026_01_06_212210_add_url_and_title_to_likha_order_settings_table.php',
            'database/migrations/2026_01_08_232304_create_macro_import_runs_table.php',
            'database/migrations/2026_01_08_232305_create_macro_import_run_items_table.php',
            'database/migrations/2026_04_10_000003_create_app_settings_table.php',
            'database/migrations/2026_05_17_120000_add_is_archived_to_likha_order_settings.php',
            'database/migrations/2026_05_18_120000_add_is_archived_to_macro_gsheet_settings.php',
            'database/migrations/2026_06_30_000200_add_cancel_requested_to_macro_import_runs.php',
            'database/migrations/2026_10_04_100000_create_night_run_steps_table.php',
            'database/migrations/2026_10_04_100100_create_night_astra_rows_table.php',
        ];
    }

    protected function user(string $role = 'CEO', string $email = 'ceo@example.test'): User
    {
        $user = User::create(['name' => $role . ' User', 'email' => $email, 'password' => 'secret-password']);
        $user->employeeProfile()->update(['role' => $role]);

        return $user->fresh();
    }
}

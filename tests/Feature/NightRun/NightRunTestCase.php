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
 * macro_output = gawa sa kamay (ang legacy migrations nito ay may MySQL-only na hakbang); ai_checker_logs,
 * pancake_conversations at ang blacklist/whitelist tables = totoong migrations.
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

        // macro_output: ang mga column lang na binabasa/sinusulat ng AI engines (totoong pangalan, kasama ang may space).
        // Walang ts_date trigger sa sqlite — ang fixtures ang naglalagay ng ts_date.
        Schema::create('macro_output', function (Blueprint $t) {
            $t->id();
            $t->string('TIMESTAMP', 100)->nullable();
            $t->string('FULL NAME')->nullable();
            $t->string('PHONE NUMBER', 100)->nullable();
            $t->text('ADDRESS')->nullable();
            $t->string('PROVINCE')->nullable();
            $t->string('CITY')->nullable();
            $t->string('BARANGAY')->nullable();
            $t->string('ITEM_NAME')->nullable();
            $t->string('COD', 50)->nullable();
            $t->string('PAGE')->nullable();
            $t->string('fb_name')->nullable();
            $t->text('all_user_input')->nullable();
            $t->text('SHOP DETAILS')->nullable();
            $t->text('CXD')->nullable();
            $t->text('AI ANALYZE')->nullable();
            $t->string('APP SCRIPT CHECKER')->nullable();
            $t->string('STATUS')->nullable();
            $t->date('ts_date')->nullable();
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
            'database/migrations/2026_01_13_135100_create_pancake_conversations_table.php',
            'database/migrations/2026_03_06_000002_create_phone_whitelist_table.php',
            'database/migrations/2026_03_06_000003_create_blacklist_tables.php',
            'database/migrations/2026_04_10_000003_create_app_settings_table.php',
            'database/migrations/2026_05_17_120000_add_is_archived_to_likha_order_settings.php',
            'database/migrations/2026_05_18_120000_add_is_archived_to_macro_gsheet_settings.php',
            'database/migrations/2026_06_23_120000_create_ai_checker_logs_table.php',
            'database/migrations/2026_06_23_130000_widen_ai_checker_logs_final_code.php',
            'database/migrations/2026_06_26_120000_create_address_keyword_blacklist_table.php',
            'database/migrations/2026_06_30_000200_add_cancel_requested_to_macro_import_runs.php',
            'database/migrations/2026_09_26_210000_add_detail_columns_to_ai_checker_logs.php',
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

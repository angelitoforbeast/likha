<?php

namespace Tests\Feature\Botcake;

use App\Http\Middleware\AllowedIpMiddleware;
use App\Models\MacroOutput;
use App\Models\User;
use App\Services\BotcakePsidSheet;
use App\Support\BotcakeClock;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Base ng page settings at Botcake API tests (sqlite in-memory). Walang totoong tawag: Http::preventStrayRequests,
 * ang orasan ay FakeBotcakeClock (naitatala ang bawat sleep), ang sheet ay FakeBotcakePsidSheet.
 */
abstract class BotcakeApiTestCase extends TestCase
{
    protected FakeBotcakeClock $clock;
    protected FakeBotcakePsidSheet $sheet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(AllowedIpMiddleware::class);
        Http::preventStrayRequests();

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
        // Binibilang ng view composer ('*') ang tasks ng user sa bawat page.
        Schema::create('tasks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('status')->nullable();
            $t->timestamps();
        });
        Schema::create('macro_output', function (Blueprint $t) {
            $t->id();
            $t->string('PAGE')->nullable();
            $t->string('fb_name')->nullable();
            $t->string('PHONE NUMBER', 100)->nullable();
            $t->boolean('edited_phone_number')->default(false);
            $t->string('botcake_psid', 64)->nullable();
            $t->date('ts_date')->nullable();
            $t->timestamps();
        });

        Artisan::call('migrate', ['--path' => [
            'database/migrations/2025_12_12_223020_create_botcake_psid_settings_table.php',
            'database/migrations/2025_12_16_232916_create_botcake_psid_import_runs_table.php',
            'database/migrations/2025_12_16_232918_create_botcake_psid_import_events_table.php',
            'database/migrations/2026_10_16_400000_create_botcake_psids_table.php',
            'database/migrations/2026_10_16_400100_add_pending_counts_to_botcake_psid_import_runs.php',
            'database/migrations/2026_10_17_100000_create_page_settings_table.php',
            'database/migrations/2026_10_17_100100_add_api_columns_to_botcake_psids.php',
        ]]);

        $this->clock = new FakeBotcakeClock();
        $this->app->instance(BotcakeClock::class, $this->clock);
        $this->sheet = new FakeBotcakePsidSheet();
        $this->app->instance(BotcakePsidSheet::class, $this->sheet);
    }

    protected function user(string $role = 'CEO', string $email = 'ceo@example.test'): User
    {
        $user = User::create(['name' => $role . ' User', 'email' => $email, 'password' => 'secret-password']);
        $user->employeeProfile()->update(['role' => $role]);

        return $user->fresh();
    }

    protected function order(string $page, string $name, ?string $phone = null, ?string $psid = null, ?string $tsDate = null): MacroOutput
    {
        return MacroOutput::create([
            'PAGE'         => $page,
            'fb_name'      => $name,
            'PHONE NUMBER' => $phone,
            'botcake_psid' => $psid,
            'ts_date'      => $tsDate ?? now('Asia/Manila')->toDateString(),
        ]);
    }

    /** Isang customer gaya ng sagot ng Botcake. */
    protected function customer(string $psid, string $name, array $phones = []): array
    {
        return ['id' => 'c-' . $psid, 'psid' => $psid, 'full_name' => $name, 'phone_number' => $phones];
    }
}

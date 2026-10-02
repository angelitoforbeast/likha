<?php

namespace Tests\Feature\Item;

use App\Http\Middleware\AllowedIpMiddleware;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Base ng mga /item test (sqlite in-memory).
 *
 * macro_output / from_jnts = minimal na legacy columns lang (hindi tumatakbo sa sqlite ang
 * ts_date trigger migration — MySQL/pgsql lang), kaya ang fixtures mismo ang nagse-set ng
 * ts_date gaya ng gagawin ng trigger. Ang supply / quote / image tables = totoong migrations.
 */
abstract class ItemTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // allowed_ip: CEO exempted; ang ibang role ay hinaharang sa 127.0.0.1 — hindi ito ang sinusubok dito.
        $this->withoutMiddleware(AllowedIpMiddleware::class);
        Storage::fake('public');

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
        Schema::create('macro_output', function (Blueprint $t) {
            $t->id();
            $t->string('ITEM_NAME')->nullable();
            $t->string('PAGE')->nullable();
            $t->string('TIMESTAMP')->nullable();
            $t->string('STATUS')->nullable();
            $t->string('waybill')->nullable();
            $t->date('ts_date')->nullable();
        });
        Schema::create('from_jnts', function (Blueprint $t) {
            $t->id();
            $t->string('waybill_number')->nullable();
            $t->string('submission_time')->nullable();
        });

        // supply_settings: kapareho ng 2026_04_24_000003 (hindi mapatakbo mag-isa — may item_class_thresholds ito).
        Schema::create('supply_settings', function (Blueprint $t) {
            $t->id();
            $t->string('key', 100)->unique();
            $t->string('value', 50);
            $t->string('label', 150);
            $t->string('group', 30)->default('general');
            $t->string('data_type', 10)->default('float');
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamps();
        });

        Artisan::call('migrate', [
            '--path'  => $this->migrationPaths(),
            '--force' => true,
        ]);
    }

    /** Totoong migrations ng mga table na ginagamit ng /item supplier features. */
    protected function migrationPaths(): array
    {
        return [
            'database/migrations/2026_04_23_000002_create_supply_item_settings_table.php',
            'database/migrations/2026_06_04_020000_create_supply_finance_tables.php',
            'database/migrations/2026_09_13_100000_create_item_images_table.php',
            'database/migrations/2026_09_27_100000_create_item_supplier_quotes_table.php',
            'database/migrations/2026_10_01_100000_create_item_supplier_quote_history_table.php',
            'database/migrations/2026_10_01_100100_add_photo_path_to_item_supplier_quotes.php',
            'database/migrations/2026_04_10_000003_create_app_settings_table.php',
            'database/migrations/2026_10_02_100000_create_item_categories_table.php',
            'database/migrations/2026_10_02_100100_create_item_category_assignments_table.php',
            'database/migrations/2026_10_02_100200_seed_item_stock_start_setting.php',
            'database/migrations/2026_10_02_100300_add_palugit_override_to_supply_item_settings.php',
            'database/migrations/2026_10_02_100400_seed_item_palugit_settings.php',
            'database/migrations/2026_10_02_100500_hide_category_column_owner_private.php',
        ];
    }

    protected function user(string $role = 'CEO', string $email = 'ceo@example.test'): User
    {
        $user = User::create(['name' => $role . ' User', 'email' => $email, 'password' => 'secret-password']);
        $user->employeeProfile()->update(['role' => $role]);

        return $user->fresh();
    }

    /** Isang macro_output order row. $date = 'Y-m-d' (TIMESTAMP + ts_date, gaya ng trigger). */
    protected function order(string $item, string $page, ?string $waybill, string $date = '2026-09-10', ?string $status = 'PROCEED'): void
    {
        [$y, $m, $d] = explode('-', $date);
        DB::table('macro_output')->insert([
            'ITEM_NAME' => $item,
            'PAGE'      => $page,
            'TIMESTAMP' => "10:15 $d-$m-$y",
            'STATUS'    => $status,
            'waybill'   => $waybill,
            'ts_date'   => $date,
        ]);
    }

    /** Waybill na nasa J&T na (shipped → hindi HOLD). */
    protected function shipped(string $waybill, ?string $submittedAt = null): void
    {
        DB::table('from_jnts')->insert(['waybill_number' => $waybill, 'submission_time' => $submittedAt]);
    }
}

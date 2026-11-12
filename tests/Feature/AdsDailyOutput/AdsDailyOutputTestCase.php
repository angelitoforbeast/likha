<?php

namespace Tests\Feature\AdsDailyOutput;

use App\Http\Middleware\AllowedIpMiddleware;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Base of the spec 266 tests (sqlite in memory). ad_catalog and the nav tables come from their real migrations;
 * ads_manager_reports (its later migrations are MySQL/Postgres-only) and page_item_settings are made by hand with the
 * columns the page reads and the indexes the live ads_manager_reports has, so the query plan is tested against them.
 * "Now" is 2026-11-12 19:00 Manila.
 */
abstract class AdsDailyOutputTestCase extends TestCase
{
    protected const NAV_MIGRATION = 'database/migrations/2026_05_18_140000_create_nav_links_and_visibility_tables.php';

    protected const LINK_MIGRATION = 'database/migrations/2026_11_25_100000_add_ads_daily_output_nav_link.php';

    protected const URL = '/ads_manager/daily-output';

    protected const D = '2026-11-12';

    protected const PREV = '2026-11-11';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(AllowedIpMiddleware::class);
        Carbon::setTestNow('2026-11-12 19:00:00');

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
        Schema::create('tasks', function (Blueprint $t) { // counted by the layout's view composer
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('status')->nullable();
            $t->timestamps();
        });
        Schema::create('ads_manager_reports', function (Blueprint $t) {
            $t->id();
            $t->date('day')->nullable();
            $t->string('page_name');
            $t->string('campaign_name')->nullable();
            $t->string('ad_set_id')->nullable();
            $t->string('ad_set_name')->nullable();
            $t->string('campaign_id')->nullable();
            $t->string('ad_id')->default('');
            $t->decimal('ad_set_budget', 12, 2)->nullable();
            $t->string('ad_set_budget_type')->nullable();
            $t->bigInteger('results')->nullable();
            $t->decimal('amount_spent_php', 12, 2)->nullable();
            $t->timestamps();
            // The live indexes (2025_11_07_171101 and 2026_05_11_120000).
            $t->unique(['day', 'campaign_id', 'ad_set_id', 'ad_id'], 'ads_report_day_campaign_adset_ad_unique');
            $t->index(['ad_id'], 'ads_report_adid_idx');
            $t->index(['campaign_id', 'ad_set_id', 'day'], 'ads_report_lookup_idx');
            $t->index(['page_name', 'day'], 'ads_report_page_day_idx');
            $t->index(['page_name'], 'ads_report_page_idx');
        });
        Schema::create('page_item_settings', function (Blueprint $t) {
            $t->id();
            $t->string('page_name');
            $t->string('item_name');
            $t->decimal('rts_pct', 5, 2)->nullable();
            $t->string('promo', 255)->default('NONE');
            $t->date('effective_date');
            $t->timestamps();
            $t->index(['page_name', 'item_name', 'effective_date']);
        });

        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_05_22_140000_create_ad_catalog_table.php',
            // Spec 268: the page's main item per day and the chatbot page settings (main flow picture).
            'database/migrations/2026_04_24_000009_create_daily_page_primary_item_table.php',
            'database/migrations/2026_10_17_100000_create_page_settings_table.php',
            'database/migrations/2026_11_03_100000_add_replaced_by_to_page_settings.php',
            'database/migrations/2026_11_12_100000_add_source_to_page_settings.php',
            self::NAV_MIGRATION,
            self::LINK_MIGRATION,
        ], '--force' => true]);
    }

    /** One daily_page_primary_item row (page_key = the page name in lowercase), as DailyPrimaryItemService writes it. */
    protected function primary(string $day, string $page, string $item): void
    {
        DB::table('daily_page_primary_item')->insert([
            'ts_date' => $day, 'page_label' => $page, 'page_key' => mb_strtolower(trim($page)),
            'primary_item' => $item, 'primary_item_key' => mb_strtolower(str_replace(' ', '', preg_replace('/^\d+\s*x\s+/i', '', $item))),
            'primary_orders' => 10, 'total_orders_all' => 12, 'computed_at' => $day.' 12:00:00',
        ]);
    }

    /** One page_settings chatbot/picture row: active while $replacedAt is null, else history. */
    protected function picture(string $page, string $url, string $createdAt, ?string $replacedAt = null): void
    {
        DB::table('page_settings')->insert([
            'page_name' => $page, 'provider' => 'chatbot', 'key' => 'picture', 'value' => $url, 'is_secret' => false,
            'active' => $replacedAt === null, 'replaced_at' => $replacedAt, 'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function user(?string $role, string $name = 'Test User'): User
    {
        $user = User::create(['name' => $name, 'email' => uniqid('u').'@example.test', 'password' => 'secret-password']);
        $user->employeeProfile()->update(['role' => $role]);

        return $user->fresh();
    }

    /**
     * One ads_manager_reports row as the Meta sync writes it: one per (day, campaign, ad set, ad).
     *
     * @param  array{0: string, 1: string}  $campaign  [id, name]
     * @param  array{0: string, 1: string}  $adSet  [id, name]
     */
    protected function report(string $day, string $page, array $campaign, array $adSet, string $adId, float $spend, ?float $budget = null, ?string $updatedAt = null): void
    {
        $updatedAt ??= $day.' 18:46:00';
        DB::table('ads_manager_reports')->insert([
            'day' => $day, 'page_name' => $page, 'campaign_id' => $campaign[0], 'campaign_name' => $campaign[1],
            'ad_set_id' => $adSet[0], 'ad_set_name' => $adSet[1], 'ad_id' => $adId, 'ad_set_budget' => $budget,
            'ad_set_budget_type' => $budget === null ? null : 'daily', 'amount_spent_php' => $spend, 'results' => 0,
            'created_at' => $updatedAt, 'updated_at' => $updatedAt,
        ]);
    }

    /** One ad_catalog row (first_started = first day the ad was seen). */
    protected function catalog(string $adId, string $firstStarted, string $page, array $campaign, array $adSet): void
    {
        DB::table('ad_catalog')->insert([
            'ad_id' => $adId, 'first_started' => $firstStarted, 'page_name' => $page,
            'campaign_id' => $campaign[0], 'campaign_name' => $campaign[1], 'ad_set_id' => $adSet[0], 'ad_set_name' => $adSet[1],
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** One page_item_settings row with its promo. */
    protected function item(string $page, string $item, string $promo, string $effective): void
    {
        DB::table('page_item_settings')->insert([
            'page_name' => $page, 'item_name' => $item, 'promo' => $promo, 'rts_pct' => 20, 'effective_date' => $effective,
        ]);
    }

    protected function open(string $query = '')
    {
        return $this->actingAs($this->user('CEO'))->get(self::URL.$query);
    }
}

<?php

namespace Tests\Feature\Access;

use App\Http\Middleware\RecordPageAccess;
use App\Models\AppSetting;
use App\Support\AccessRule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * AC-2: one page-access rule on every signed-in page, recording only by default.
 *
 * The pages here are stubs on the real paths (the real controllers have their own checks, which the middleware
 * never loosens), so a test only sees what the middleware does. The nav links are the real seed: `finance/supply*`
 * is visible to the CEO only, `ads_manager/report*` and `jnt/supply*` to Marketing, `jnt/supply/excluded-pages*`
 * to the CEO only.
 */
class PageAccessRecordingTest extends AccessTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $page = fn (string $name) => fn () => response("<html><body>$name page</body></html>");
        Route::middleware(['web', 'auth'])->group(function () use ($page) {
            Route::get('/finance/supply', $page('Supply'));
            Route::get('/finance/supply/{supplier}', $page('Supplier'));
            Route::get('/ads_manager/report', $page('Ads'));
            Route::get('/jnt/supply', $page('Jnt supply'));
            Route::get('/jnt/supply/excluded-pages', $page('Excluded'));
            Route::get('/test-access/no-home/{id}', $page('No home'));
            Route::get('/checklist/test-open', $page('Checklist'));
            Route::get('/profile/test-open', $page('Profile'));
            Route::get('/finance/supply-json', fn () => response()->json(['ok' => true]));
            Route::get('/finance/supply-file', fn () => response()->streamDownload(fn () => print('a,b'), 'x.csv'));
            Route::get('/api/test-access', fn () => response()->json(['ok' => true]));
            Route::post('/finance/supply-post', $page('Posted'));
        });
    }

    private function rows()
    {
        return DB::table('access_records')->orderBy('id')->get();
    }

    public function test_a_marketing_user_on_a_ceo_page_is_allowed_and_one_would_block_row_is_counted(): void
    {
        $user = $this->staff('Marketing');

        $this->actingAs($user)->get('/finance/supply')->assertOk()->assertSee('Supply page');

        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame($user->id, (int) $rows[0]->user_id);
        $this->assertSame('Marketing', $rows[0]->role);
        $this->assertSame('/finance/supply', $rows[0]->path_pattern);
        $this->assertSame('would_block', $rows[0]->decision);
        $this->assertSame(1, (int) $rows[0]->hits);

        $this->actingAs($user)->get('/finance/supply')->assertOk();

        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame(2, (int) $rows[0]->hits);
    }

    public function test_the_ceo_is_never_recorded(): void
    {
        $this->actingAs($this->staff('CEO'))->get('/finance/supply')->assertOk();
        $this->actingAs($this->staff('CEO'))->get('/test-access/no-home/3')->assertOk();

        $this->assertCount(0, $this->rows());
    }

    public function test_open_list_pages_are_never_recorded(): void
    {
        $user = $this->staff('Data Encoder');
        $this->actingAs($user)->get('/checklist/test-open')->assertOk();
        $this->actingAs($user)->get('/profile/test-open')->assertOk();

        $this->assertCount(0, $this->rows());
    }

    public function test_a_page_whose_link_is_visible_for_the_role_is_not_recorded(): void
    {
        // Role compared after trimming and collapsing spaces, as NavAccess does.
        $this->actingAs($this->staff('  Marketing   - OIC '))->get('/ads_manager/report')->assertOk();
        $this->actingAs($this->staff('Marketing'))->get('/jnt/supply')->assertOk();

        $this->assertCount(0, $this->rows());
    }

    public function test_the_longest_nav_match_wins(): void
    {
        // jnt/supply* is visible to Marketing, but jnt/supply/excluded-pages* (longer) is CEO only.
        $this->actingAs($this->staff('Marketing'))->get('/jnt/supply/excluded-pages')->assertOk();

        $this->assertSame(['/jnt/supply/excluded-pages' => 'would_block'], $this->rows()->pluck('decision', 'path_pattern')->all());
    }

    public function test_a_page_no_link_covers_is_allowed_and_recorded_as_unmapped_with_its_route_pattern_only(): void
    {
        $this->actingAs($this->staff('Marketing'))->get('/test-access/no-home/42?token=secret-value')->assertOk();

        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame('/test-access/no-home/{id}', $rows[0]->path_pattern);
        $this->assertSame('unmapped', $rows[0]->decision);
        $this->assertStringNotContainsString('secret', json_encode($rows[0]));
        $this->assertStringNotContainsString('42', $rows[0]->path_pattern);
    }

    public function test_a_page_with_parameters_counts_on_its_route_pattern(): void
    {
        $user = $this->staff('Marketing');
        $this->actingAs($user)->get('/finance/supply/1')->assertOk();
        $this->actingAs($user)->get('/finance/supply/2')->assertOk();

        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame('/finance/supply/{supplier}', $rows[0]->path_pattern);
        $this->assertSame(2, (int) $rows[0]->hits);
    }

    public function test_a_user_without_a_profile_is_recorded_with_an_empty_role(): void
    {
        $this->actingAs($this->staff(false))->get('/finance/supply')->assertOk();

        $this->assertSame([['', 'would_block']], $this->rows()->map(fn ($r) => [$r->role, $r->decision])->all());
    }

    public function test_json_files_api_writes_and_guests_are_not_recorded(): void
    {
        $user = $this->staff('Marketing');
        $this->actingAs($user)->get('/finance/supply-json')->assertOk();
        $this->actingAs($user)->getJson('/finance/supply')->assertOk();
        $this->actingAs($user)->get('/finance/supply', ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();
        $this->actingAs($user)->get('/finance/supply-file')->assertOk();
        $this->actingAs($user)->get('/api/test-access')->assertOk();
        $this->actingAs($user)->post('/finance/supply-post')->assertOk();

        $this->assertCount(0, $this->rows());
    }

    public function test_the_day_is_the_manila_date_and_a_new_day_starts_a_new_row(): void
    {
        $user = $this->staff('Marketing');

        Carbon::setTestNow(Carbon::parse('2026-11-18 17:30:00', 'UTC'));   // 01:30 on the 19th in Manila
        $this->actingAs($user)->get('/finance/supply')->assertOk();
        Carbon::setTestNow(Carbon::parse('2026-11-19 16:30:00', 'UTC'));   // 00:30 on the 20th in Manila
        $this->actingAs($user)->get('/finance/supply')->assertOk();
        Carbon::setTestNow();

        $this->assertSame(['2026-11-19', '2026-11-20'], $this->rows()->map(fn ($r) => substr((string) $r->day, 0, 10))->all());
    }

    public function test_the_mode_defaults_to_record(): void
    {
        $this->assertSame('record', app(AccessRule::class)->mode());
    }

    public function test_enforce_mode_answers_a_plain_403_page_and_still_counts_it(): void
    {
        AppSetting::set('access.mode', 'enforce');

        $this->actingAs($this->staff('Marketing'))->get('/finance/supply')
            ->assertForbidden()
            ->assertSee("You don't have access to this page. Ask your OIC or the owner.", false)
            ->assertDontSee('Supply page');

        $this->assertSame(['would_block'], $this->rows()->pluck('decision')->all());
    }

    public function test_enforce_mode_never_blocks_the_ceo_visible_open_or_unmapped_pages(): void
    {
        AppSetting::set('access.mode', 'enforce');

        $this->actingAs($this->staff('CEO'))->get('/finance/supply')->assertOk();
        $marketing = $this->staff('Marketing');
        $this->actingAs($marketing)->get('/ads_manager/report')->assertOk();
        $this->actingAs($marketing)->get('/checklist/test-open')->assertOk();
        $this->actingAs($marketing)->get('/test-access/no-home/1')->assertOk();
        $this->actingAs($marketing)->getJson('/finance/supply')->assertOk();   // not a page: never blocked here
    }

    public function test_the_middleware_never_loosens_a_controller_check(): void
    {
        // /roles/index has its own CEO check (AC-1); recording mode still answers its 403.
        $this->actingAs($this->staff('Marketing'))->get('/roles/index')->assertForbidden();
    }

    public function test_the_middleware_never_throws_when_the_rule_fails(): void
    {
        Log::spy();
        $this->app->instance(AccessRule::class, new class extends AccessRule {
            public function decide(?string $role, string $path): string
            {
                throw new \RuntimeException('rule broke');
            }
        });

        $this->actingAs($this->staff('Marketing'))->get('/finance/supply')->assertOk()->assertSee('Supply page');

        Log::shouldHaveReceived('warning')->withArgs(fn ($msg, $ctx = []) => str_contains($msg, 'RecordPageAccess')
            && str_contains($ctx['error'] ?? '', 'rule broke'));
    }

    public function test_the_middleware_never_throws_in_enforce_mode_either(): void
    {
        AppSetting::set('access.mode', 'enforce');
        $this->app->instance(AccessRule::class, new class extends AccessRule {
            public function decide(?string $role, string $path): string
            {
                throw new \RuntimeException('rule broke');
            }
        });

        $this->actingAs($this->staff('Marketing'))->get('/finance/supply')->assertOk();
    }

    public function test_missing_tables_are_skipped_without_an_error(): void
    {
        Schema::drop('access_records');
        Schema::drop('nav_link_role_visibility');
        Schema::drop('nav_links');
        Schema::drop('app_settings');

        $this->actingAs($this->staff('Marketing'))->get('/finance/supply')->assertOk();
    }

    public function test_the_nav_map_is_read_once_and_cached_and_api_and_json_routes_add_no_query(): void
    {
        $user = $this->staff('Marketing');
        $this->actingAs($user)->get('/finance/supply')->assertOk();   // warms the 60 s cache

        $queries = [];
        DB::listen(function ($q) use (&$queries) { $queries[] = $q->sql; });

        $this->actingAs($user)->get('/finance/supply')->assertOk();
        $touch = fn (string $t) => array_values(array_filter($queries, fn ($s) => str_contains($s, $t)));
        $this->assertSame([], $touch('nav_link'), 'the nav map comes from the cache');
        $this->assertSame([], $touch('app_settings'), 'the mode comes from the cache');
        $this->assertSame([], $touch('sqlite_master'), 'the table checks come from the cache');
        $this->assertCount(1, $touch('access_records'), 'one upsert per page view');

        // API, JSON and open-list routes: the same queries with and without the middleware.
        foreach (['/api/test-access', '/finance/supply-json', '/checklist/test-open'] as $url) {
            $count = function () use ($user, $url) {
                $n = 0;
                DB::listen(function () use (&$n) { $n++; });
                $this->actingAs($user)->get($url)->assertOk();

                return $n;
            };
            $with = $count();
            $this->withoutMiddleware(RecordPageAccess::class);
            $without = $count();
            $this->app->forgetInstance(RecordPageAccess::class);
            $this->assertSame($without, $with, "$url adds no query");
        }
    }
}

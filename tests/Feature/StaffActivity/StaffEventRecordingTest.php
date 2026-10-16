<?php

namespace Tests\Feature\StaffActivity;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Part A: ang middleware sa `web` group (last seen + staff_events), ang login/logout listener at ang
 * pag-prune ng lumang events. Walang body o query string na pumapasok sa log.
 */
class StaffEventRecordingTest extends StaffActivityTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->group(function () {
            Route::post('/t/orders/{id}/save', fn () => redirect('/t/done'))->name('t.orders.save');
            Route::post('/t/unnamed/{task}', fn () => response('ok'));
            Route::get('/t/page', fn () => 'page')->name('t.page');
            Route::post('/t/invalid', fn () => abort(422))->name('t.invalid');
            Route::post('/t/boom', fn () => throw new \RuntimeException('boom'))->name('t.boom');
        });
    }

    public function test_a_successful_post_writes_one_event_with_the_route_name_and_no_body(): void
    {
        $user = $this->staff('Data Encoder');

        $this->actingAs($user)
            ->post('/t/orders/42/save?customer=Juan', ['address' => 'Lihim na address', 'password' => 'pw'])
            ->assertRedirect('/t/done');

        $events = $this->events();
        $this->assertCount(1, $events);
        $e = (array) $events[0];
        $this->assertSame($user->id, (int) $e['user_id']);
        $this->assertSame('Data Encoder', $e['role']);
        $this->assertSame('t.orders.save', $e['kind']);
        $this->assertSame('POST', $e['method']);
        $this->assertSame(302, (int) $e['status']);
        $this->assertSame('42', $e['ref']);
        $this->assertNotNull($e['created_at']);
        $this->assertSame(
            ['id', 'user_id', 'role', 'kind', 'method', 'status', 'ref', 'created_at'],
            array_keys($e)
        );
        $this->assertStringNotContainsString('Lihim', json_encode($e));
        $this->assertStringNotContainsString('Juan', json_encode($e));
    }

    public function test_an_unnamed_route_is_recorded_as_method_and_path_pattern(): void
    {
        $this->actingAs($this->staff())->post('/t/unnamed/7')->assertOk();

        $e = $this->events()->sole();
        $this->assertSame('POST t/unnamed/{task}', $e->kind);
        $this->assertSame('7', $e->ref);
    }

    public static function notRecorded(): array
    {
        return [
            'GET'   => ['get', '/t/page', 200],
            '4xx'   => ['post', '/t/invalid', 422],
            '5xx'   => ['post', '/t/boom', 500],
        ];
    }

    #[DataProvider('notRecorded')]
    public function test_reads_and_failed_writes_write_no_event(string $method, string $uri, int $status): void
    {
        $this->actingAs($this->staff())->$method($uri)->assertStatus($status);

        $this->assertCount(0, $this->events());
    }

    public function test_a_csrf_failure_writes_no_event(): void
    {
        // Sa testing env nilalaktawan ang CSRF check; ilipat sa ibang env para totoong 419.
        $this->app['env'] = 'local';

        $this->actingAs($this->staff())->post('/t/orders/1/save', ['_token' => 'mali'])->assertStatus(419);

        $this->assertCount(0, $this->events());
    }

    public function test_guests_write_no_event_and_no_last_seen(): void
    {
        $this->post('/t/unnamed/1')->assertOk();

        $this->assertCount(0, $this->events());
    }

    public function test_last_seen_updates_at_most_once_a_minute(): void
    {
        $user = $this->staff();
        Carbon::setTestNow('2026-10-16 09:00:00');

        $this->actingAs($user)->get('/t/page?secret=1')->assertOk();
        $row = DB::table('users')->where('id', $user->id)->first(['last_seen_at', 'last_seen_path']);
        $this->assertSame('2026-10-16 09:00:00', (string) $row->last_seen_at);
        $this->assertSame('t/page', $row->last_seen_path);

        Carbon::setTestNow('2026-10-16 09:00:30');
        $this->actingAs($user)->post('/t/unnamed/3')->assertOk();
        $this->assertSame('2026-10-16 09:00:00', (string) DB::table('users')->where('id', $user->id)->value('last_seen_at'));

        Carbon::setTestNow('2026-10-16 09:01:01');
        $this->travelTo(Carbon::now()); // cache TTL sumusunod sa test clock
        $this->actingAs($user)->post('/t/unnamed/3')->assertOk();
        $row = DB::table('users')->where('id', $user->id)->first(['last_seen_at', 'last_seen_path']);
        $this->assertSame('2026-10-16 09:01:01', (string) $row->last_seen_at);
        $this->assertSame('t/unnamed/3', $row->last_seen_path);
    }

    public function test_login_sets_last_login_and_writes_login_and_logout_events(): void
    {
        $user = $this->staff('Marketing');
        Carbon::setTestNow('2026-10-16 08:30:00');

        Auth::login($user);
        Auth::logout();

        $this->assertSame('2026-10-16 08:30:00', (string) DB::table('users')->where('id', $user->id)->value('last_login_at'));
        $this->assertSame(['login', 'logout'], $this->events()->pluck('kind')->all());
        $this->assertSame(['Marketing', 'Marketing'], $this->events()->pluck('role')->all());
        $this->assertSame([$user->id, $user->id], $this->events()->pluck('user_id')->map(fn ($v) => (int) $v)->all());
    }

    public function test_the_login_and_logout_posts_are_not_logged_twice(): void
    {
        $user = $this->staff();

        $this->post('/login', ['email' => $user->email, 'password' => 'secret-password'])->assertRedirect();
        $this->post('/logout')->assertRedirect();

        $this->assertSame(['login', 'logout'], $this->events()->pluck('kind')->all());
    }

    public function test_prune_removes_only_events_older_than_90_days(): void
    {
        Carbon::setTestNow('2026-10-16 12:00:00');
        foreach (['old' => '2026-07-18 11:59:59', 'edge' => '2026-07-18 12:00:01', 'new' => '2026-10-15 10:00:00'] as $kind => $at) {
            DB::table('staff_events')->insert(['user_id' => 1, 'kind' => $kind, 'created_at' => $at]);
        }

        $this->artisan('staff-events:prune')->assertSuccessful();

        $this->assertSame(['edge', 'new'], $this->events()->pluck('kind')->all());
    }

    public function test_prune_is_scheduled_daily_at_0420_manila(): void
    {
        $this->app->forgetInstance(Schedule::class);
        ScheduleFacade::clearResolvedInstance(Schedule::class);
        require base_path('routes/console.php');

        $events = array_values(array_filter(
            $this->app->make(Schedule::class)->events(),
            fn ($e) => str_contains((string) $e->command, 'staff-events:prune'),
        ));

        $this->assertCount(1, $events);
        $this->assertSame('20 4 * * *', $events[0]->expression);
        $this->assertSame('Asia/Manila', (string) $events[0]->timezone);
    }
}

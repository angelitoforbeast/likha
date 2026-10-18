<?php

namespace Tests\Feature\PageSettings;

use App\Jobs\SyncAllBotcakeCustomersJob;
use App\Jobs\SyncBotcakeCustomersJob;
use App\Models\BotcakePsidImportRun;
use App\Services\BotcakeCustomerSync;
use App\Services\BotcakeSyncAll;
use App\Services\PageSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Botcake\BotcakeApiTestCase;

/**
 * "Sync lahat": isang sync kada page, isa-isa, sa ayos ng grid, ayon sa plano ng bawat page; ang sariwang by date
 * ay nilalaktawan. Ang run ay nasa database (source sync_all): Pause, Resume at Stop; at ang lumang run sa cache ay inaampon.
 */
class SyncAllTest extends BotcakeApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->user());

        // Ayos ng grid: Page Dos (3 order), Page Tres (2), Page Uno (1). Walang token / invalid: hindi kasama.
        $s = app(PageSettings::class);
        foreach (['Page Uno' => ['1001', 1], 'Page Dos' => ['2002', 3], 'Page Tres' => ['3003', 2]] as $page => [$id, $orders]) {
            $s->set($page, 'botcake', 'page_id', $id);
            $s->set($page, 'botcake', 'token', 'tok-sync-all-' . $id);
            for ($i = 0; $i < $orders; $i++) $this->order($page, 'Buyer ' . $i);
        }
        $s->set('Page Walang Token', 'botcake', 'page_id', '4004');
        $this->order('Page Walang Token', 'Buyer');
        $s->set('Page Invalid', 'botcake', 'page_id', '5005');
        $s->set('Page Invalid', 'botcake', 'token', 'tok-sync-all-5005');
        $s->set('Page Invalid', 'botcake', 'token_status', 'invalid 2026-10-17 08:00', false, null, true);
    }

    private function press()
    {
        return $this->postJson('/page-settings/sync-all');
    }

    private function panel(): ?array
    {
        return $this->getJson('/page-settings/grid?since=' . urlencode(now()->subMinute()->format('Y-m-d H:i:s')))->assertOk()->json('sync_all');
    }

    /** @return array<int, string> ang page id ng bawat Botcake request, sa ayos ng pagpapadala */
    private function calledPages(): array
    {
        return collect(Http::recorded())->map(fn ($r) => preg_replace('#.*/pages/(\d+)/.*#', '$1', $r[0]->url()))->unique()->values()->all();
    }

    private function jobs(): \Illuminate\Support\Collection
    {
        return Queue::pushed(SyncAllBotcakeCustomersJob::class);
    }

    private function runJob(SyncAllBotcakeCustomersJob $job): void
    {
        $job->handle(app(BotcakeCustomerSync::class), app(BotcakeSyncAll::class));
    }

    /** Ang Page Uno ay may malinis na window read (hanggang ngayon) 2 oras na ang nakalipas: by date na, at sariwa. */
    private function freshUno(): void
    {
        $run = BotcakePsidImportRun::create(['status' => 'done', 'source' => 'api', 'page_name' => 'Page Uno', 'stop_reason' => 'end', 'read_kind' => 'window', 'start_batch' => 1, 'subscribed_newest' => now()->toDateString()]);
        DB::table('botcake_psid_import_runs')->where('id', $run->id)->update(['updated_at' => now()->subHours(2)]);
    }

    public function test_pages_run_one_at_a_time_in_grid_order_skip_fresh_ones_and_a_failing_page_does_not_stop_the_next(): void
    {
        $this->freshUno();
        Http::fake([
            'botcake.io/api/public_api/v1/pages/3003/*' => Http::response(['message' => 'down'], 500),
            'botcake.io/api/public_api/v1/pages/*'      => Http::response([], 200),
        ]);

        $this->press()->assertOk()->assertJsonPath('status', 'started')->assertJsonPath('sync_all.total', 2);

        $this->assertSame(['2002', '3003'], $this->calledPages(), 'Dos, saka Tres; ang Uno ay sariwa; walang 4004 at 5005');
        $p = $this->panel();
        $this->assertSame(['done', 2, 2, 1, 1, 1, 0], [$p['status'], $p['total'], $p['done'], $p['ok'], $p['failed'], $p['skipped'], $p['remaining']]);
        $this->assertNotNull($p['finished_at']);
        $this->assertSame('CEO User', $p['started_by']);
        $this->assertSame(BotcakeSyncAll::SOURCE, BotcakePsidImportRun::where('run_key', $p['key'])->value('source'));
    }

    public function test_the_row_sync_now_ignores_the_freshness_rule(): void
    {
        $this->freshUno();
        Http::fake(['botcake.io/api/public_api/v1/pages/*' => Http::response([], 200)]);

        SyncBotcakeCustomersJob::dispatch('Page Uno');

        $this->assertSame(['1001'], $this->calledPages());
        $this->assertSame('by_date', BotcakePsidImportRun::where('source', 'api')->latest('id')->value('read_kind'), 'ang row Sync ay by date');
    }

    public function test_sync_all_runs_a_by_date_read_for_a_page_that_finished_its_window(): void
    {
        $this->freshUno();
        config(['services.botcake.sync_all_fresh_hours' => 1]); // 2 oras na: hindi na sariwa
        Http::fake(['botcake.io/api/public_api/v1/pages/*' => Http::response([], 200)]);

        $this->press()->assertOk();

        $run = BotcakePsidImportRun::where('source', 'api')->where('page_name', 'Page Uno')->latest('id')->first();
        $this->assertSame(['by_date', now()->subDay()->toDateString()], [$run->read_kind, $run->stop_date?->toDateString()]);
    }

    public function test_the_panel_sums_the_pages_and_keeps_the_last_10_runs(): void
    {
        Queue::fake();
        // Page Dos: 3 customer sa unang batch, saka wala na (end); ang mga pangalan ay tugma sa 3 order nito.
        Http::fake(['botcake.io/*' => fn (Request $req) => Http::response(str_contains($req->url(), '/2002/') && str_contains($req->url(), 'page=1')
            ? array_map(fn ($i) => $this->customer('n' . $i, 'Buyer ' . $i), range(0, 2)) : [])]);
        $this->press()->assertOk();
        $this->runJob($this->jobs()->sole());

        $p = $this->panel();
        $this->assertSame(['running', 1, 2], [$p['status'], $p['done'], $p['remaining']]);
        $this->assertSame(3, $p['totals']['new']);
        $this->assertSame(3, $p['totals']['matched'], 'tatlong order ng Page Dos ang tumugma sa pangalan');

        app(BotcakeSyncAll::class)->stop();
        for ($i = 0; $i < 11; $i++) {
            BotcakePsidImportRun::create(['status' => 'done', 'source' => BotcakeSyncAll::SOURCE, 'run_key' => 'old-' . $i, 'page_index' => 0, 'sync_state' => ['pages' => []]]);
        }
        $history = $this->panel()['history'];
        $this->assertCount(10, $history);
        $this->assertSame('old-10', $history[0]['key'], 'pinakabago muna');
    }

    public function test_a_held_lock_makes_the_page_wait_then_run_and_the_poll_shows_it(): void
    {
        Queue::fake();
        Http::fake(['botcake.io/api/public_api/v1/pages/*' => Http::response([], 200)]);
        $this->press()->assertOk();
        $first = $this->jobs()->sole();

        $held = Cache::lock(BotcakeCustomerSync::LOCK, 60);
        $this->assertTrue($held->get(), 'ang row Sync now o ang schedule ay may hawak');
        $this->runJob($first);

        Http::assertNothingSent();
        $again = $this->jobs()->last();
        $this->assertNotSame($first, $again);
        $this->assertSame(0, $again->index, 'parehong page ulit, hindi nilaktawan');
        $this->assertNotNull($again->delay);
        $p = $this->panel();
        $this->assertSame(['Page Dos', true, 0], [$p['current'], $p['waiting'], $p['done']]);

        $held->release();
        $this->runJob($again);

        $this->assertSame(['2002'], $this->calledPages());
        $this->assertSame(1, $this->jobs()->last()->index);
        $this->assertSame([1, false, 'Page Tres'], [$this->panel()['done'], $this->panel()['waiting'], $this->panel()['current']], 'ang susunod na page ay may hawak nang job');

        // Pinatay ang job (hal. timeout): failed ang page, at tuloy pa rin ang susunod.
        $this->jobs()->last()->failed(new \RuntimeException('timeout'));
        $this->assertSame(2, $this->jobs()->last()->index);
        $this->assertSame([2, 1], [$this->panel()['done'], $this->panel()['failed']]);
    }

    public function test_pause_lets_the_running_page_finish_survives_a_cache_flush_and_resume_continues_from_the_next_page(): void
    {
        Queue::fake();
        // Pinindot ang Pause habang binabasa ang Page Dos.
        Http::fake(['botcake.io/api/public_api/v1/pages/*' => function (Request $req) {
            if (str_contains($req->url(), '/2002/')) $this->postJson('/page-settings/sync-all/pause')->assertOk()->assertJsonPath('sync_all.status', 'paused');
            return Http::response([]);
        }]);
        $this->press()->assertOk();
        $this->runJob($this->jobs()->sole());

        $this->assertCount(1, $this->jobs(), 'walang susunod na page na naka-queue');
        $this->assertSame(['paused', 1], [$this->panel()['status'], $this->panel()['done']]);
        $this->assertTrue(BotcakePsidImportRun::where('source', 'api')->where('page_name', 'Page Dos')->where('status', 'done')->exists(), 'natapos ang Page Dos');

        Cache::flush();
        $this->press()->assertOk()->assertJsonPath('status', 'running'); // naka-pause pa rin: walang bagong run
        $this->assertSame('paused', $this->panel()['status']);

        $this->postJson('/page-settings/sync-all/resume')->assertOk()->assertJsonPath('sync_all.status', 'running');
        $this->assertSame(1, $this->jobs()->last()->index, 'tuloy sa Page Tres, hindi mula sa simula');

        // Isang lumang job ng parehong run (hal. naulit) ay walang ginagawa.
        $this->runJob(new SyncAllBotcakeCustomersJob($this->jobs()->last()->runId, 0));
        $this->assertSame(1, $this->panel()['done']);
    }

    public function test_stop_ends_the_run_and_the_next_press_starts_a_new_one(): void
    {
        Queue::fake();
        Http::fake(['botcake.io/*' => Http::response([])]);
        $this->press()->assertOk();
        $job = $this->jobs()->sole();

        $this->postJson('/page-settings/sync-all/stop')->assertOk()->assertJsonPath('sync_all.status', 'stopped');
        $this->runJob($job);

        Http::assertNothingSent();
        $this->assertCount(1, $this->jobs());
        $this->assertNotNull($this->panel()['finished_at']);

        $this->press()->assertOk()->assertJsonPath('status', 'started');
        $this->assertSame(2, BotcakePsidImportRun::where('source', BotcakeSyncAll::SOURCE)->count());
    }

    public function test_a_second_press_during_a_run_starts_nothing(): void
    {
        Queue::fake();

        $this->press()->assertOk()->assertJsonPath('status', 'started');
        $this->press()->assertOk()->assertJsonPath('status', 'running')->assertJsonPath('sync_all.done', 0);

        Queue::assertPushed(SyncAllBotcakeCustomersJob::class, 1);
    }

    /** Ang state na isinusulat ng lumang code sa cache ('botcake-sync-all'). */
    private function legacy(int $done, ?string $current, string $id = 'legacy-run'): void
    {
        Cache::put(BotcakeSyncAll::LEGACY_STATE, [
            'id' => $id, 'pages' => ['Page Dos', 'Page Tres', 'Page Uno'], 'done' => $done, 'ok' => $done, 'failed' => 0,
            'current' => $current, 'waiting' => false, 'started_at' => now()->subHour()->toIso8601String(), 'finished_at' => null,
        ], 3600);
    }

    public function test_an_old_run_in_the_cache_is_adopted_and_continued_from_where_it_is(): void
    {
        Queue::fake();
        Http::fake(['botcake.io/*' => Http::response([])]);
        // Ang lumang code ay nasa Page Tres pa (index 1) habang dumating ang bagong code.
        $this->legacy(1, 'Page Tres');

        $this->press()->assertOk()->assertJsonPath('status', 'running');
        Queue::assertNothingPushed(); // hindi nagsisimula ng ikalawang run, hindi nagpapadala ng job habang may tumatakbo
        $p = $this->panel();
        $this->assertSame(['legacy-run', 'running', 3, 1, 'Page Tres'], [$p['key'], $p['status'], $p['total'], $p['done'], $p['current']]);
        $this->postJson('/page-settings/sync-all/resume')->assertOk();
        Queue::assertNothingPushed();

        // Natapos ng lumang code ang Page Tres at nagpadala ng job para sa index 2 (tatakbo sa bagong code).
        $this->legacy(2, 'Page Tres');
        $this->runJob(new SyncAllBotcakeCustomersJob('legacy-run', 2));

        $this->assertSame(['1001'], $this->calledPages(), 'ang Page Uno lang, hindi mula sa simula');
        $this->assertNull(Cache::get(BotcakeSyncAll::LEGACY_STATE), 'ang database na ang may hawak');
        $this->assertSame(['done', 3], [$this->panel()['status'], $this->panel()['done']]);
        $this->assertSame(1, BotcakePsidImportRun::where('source', BotcakeSyncAll::SOURCE)->count());
    }

    public function test_a_job_of_an_unknown_run_logs_a_warning_and_stops(): void
    {
        Http::fake();
        Log::spy();

        $this->runJob(new SyncAllBotcakeCustomersJob('wala-nito', 0));

        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($msg, $ctx = []) => str_contains($msg, 'Sync lahat') && $ctx === ['run_id' => 'wala-nito', 'index' => 0]);
        Http::assertNothingSent();
    }

    public function test_editing_cells_does_not_use_up_the_limit_of_the_sync_and_test_buttons(): void
    {
        Queue::fake();
        Http::fake(['botcake.io/*' => Http::response(['success' => true, 'data' => []])]);

        for ($i = 0; $i < 25; $i++) {
            $this->postJson('/page-settings/cell', ['page_name' => 'Page Uno', 'provider' => 'sms', 'key' => 'sender', 'value' => 'S' . $i])->assertOk();
        }

        $this->press()->assertOk();
        $this->post('/page-settings/sync', ['page_name' => 'Page Uno'])->assertRedirect();
        $this->post('/page-settings/test-token', ['page_name' => 'Page Uno'])->assertRedirect();
    }

    public function test_the_ceo_and_a_role_checked_in_nav_settings_may_use_the_controls_others_get_404_and_no_token_is_sent(): void
    {
        Queue::fake();
        Artisan::call('migrate', ['--path' => 'database/migrations/2026_05_18_140000_create_nav_links_and_visibility_tables.php', '--force' => true]);
        $link = DB::table('nav_links')->insertGetId(['key' => 'page_settings_test', 'label' => 'Page Settings', 'route_url' => '/page-settings', 'is_active' => true, 'sort_order' => 999, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('nav_link_role_visibility')->insert(['nav_link_id' => $link, 'role' => 'Marketing - OIC', 'is_visible' => true, 'created_at' => now(), 'updated_at' => now()]);

        $body = $this->press()->assertOk()->getContent();
        $this->assertStringNotContainsString('tok-sync-all', $body);

        $this->actingAs($this->user('Marketing - OIC', 'oic@example.test'));
        $this->postJson('/page-settings/sync-all/pause')->assertOk()->assertJsonPath('sync_all.status', 'paused');
        $this->postJson('/page-settings/sync-all/resume')->assertOk();

        $this->actingAs($this->user('Data Encoder', 'enc@example.test'));
        foreach (['', '/pause', '/resume', '/stop'] as $path) $this->postJson('/page-settings/sync-all' . $path)->assertNotFound();
        $this->assertSame('running', BotcakePsidImportRun::where('source', BotcakeSyncAll::SOURCE)->value('status'));
        Queue::assertPushed(SyncAllBotcakeCustomersJob::class, 1);

        $this->actingAs($this->user('Marketing - OIC', 'oic2@example.test'));
        $this->postJson('/page-settings/sync-all/stop')->assertOk()->assertJsonPath('sync_all.status', 'stopped');
    }

    public function test_an_adopted_old_run_that_the_old_code_finishes_is_closed(): void
    {
        Queue::fake();
        $this->legacy(2, 'Page Uno');
        $this->assertSame(['running', 'Page Uno'], [$this->panel()['status'], $this->panel()['current']]);

        // Natapos ng lumang code ang huling page: finished_at sa cache, at walang susunod na job.
        Cache::put(BotcakeSyncAll::LEGACY_STATE, ['id' => 'legacy-run', 'pages' => ['Page Dos', 'Page Tres', 'Page Uno'], 'done' => 3, 'ok' => 3,
            'failed' => 0, 'current' => 'Page Uno', 'waiting' => false, 'started_at' => now()->subHour()->toIso8601String(), 'finished_at' => now()->toIso8601String()], 3600);

        $p = $this->panel();
        $this->assertSame(['done', 3, 3, null], [$p['status'], $p['done'], $p['ok'], $p['current']]);
        $this->press()->assertOk()->assertJsonPath('status', 'started');
    }

    public function test_a_job_that_arrives_while_paused_lets_resume_queue_the_page_again(): void
    {
        Queue::fake();
        Http::fake(['botcake.io/*' => Http::response([])]);
        $this->press()->assertOk();
        $this->postJson('/page-settings/sync-all/pause')->assertOk();

        $this->runJob($this->jobs()->sole());
        Http::assertNothingSent();
        $this->assertNull($this->panel()['current'], 'binitawan ng job ang page');

        $this->postJson('/page-settings/sync-all/resume')->assertOk();
        $this->assertCount(2, $this->jobs());
        $this->assertSame(0, $this->jobs()->last()->index, 'ang parehong page, hindi pa ito natatapos');
    }

    public function test_stop_while_a_page_waits_on_the_lock_ends_the_run_without_syncing_it(): void
    {
        Queue::fake();
        Http::fake(['botcake.io/*' => Http::response([])]);
        $this->press()->assertOk();
        $held = Cache::lock(BotcakeCustomerSync::LOCK, 60);
        $held->get();
        $this->runJob($this->jobs()->sole());
        $this->assertTrue($this->panel()['waiting']);

        $this->postJson('/page-settings/sync-all/stop')->assertOk();
        $held->release();
        $this->runJob($this->jobs()->last());

        Http::assertNothingSent();
        $p = $this->panel();
        $this->assertSame(['stopped', null, false, 0], [$p['status'], $p['current'], $p['waiting'], $p['done']]);
        $this->assertCount(2, $this->jobs());
    }

    public function test_before_the_migration_the_page_the_poll_the_press_and_the_jobs_do_not_fail(): void
    {
        // Ang ilang segundo sa pagitan ng push at ng migrate: wala pa ang mga bagong column.
        \Illuminate\Support\Facades\Schema::table('botcake_psid_import_runs', function ($t) {
            $t->dropIndex(['run_key']);
            $t->dropColumn(['ad_filled', 'run_key', 'sync_state', 'page_index', 'current_page', 'started_by', 'finished_at']);
        });
        Queue::fake();
        Http::fake(['botcake.io/*' => Http::response([])]);
        $this->legacy(1, 'Page Tres');

        $this->get('/page-settings')->assertOk();
        $this->assertNull($this->panel());
        $this->press()->assertOk()->assertJsonPath('status', 'not_ready');
        Queue::assertNothingPushed();

        // Ang job ng lumang kadena na tumakbo sa bagong code bago ang migrate: hindi nawawala ang kadena.
        $this->runJob(new SyncAllBotcakeCustomersJob('legacy-run', 1));
        Http::assertNothingSent();
        $again = $this->jobs()->sole();
        $this->assertSame(['legacy-run', 1], [$again->runId, $again->index]);
        $this->assertNotNull($again->delay);
        $this->assertNotNull(Cache::get(BotcakeSyncAll::LEGACY_STATE), 'hindi pa naaampon');

        // Ang row Sync now ay gumagana pa rin (walang ad_filled na isinusulat).
        (new SyncBotcakeCustomersJob('Page Uno'))->handle(app(BotcakeCustomerSync::class));
        $this->assertSame('done', BotcakePsidImportRun::where('source', 'api')->where('page_name', 'Page Uno')->value('status'));
    }

    public function test_a_press_waits_for_an_adoption_in_progress_and_never_starts_a_second_run(): void
    {
        Queue::fake();
        $this->legacy(1, 'Page Tres');
        $all = app(BotcakeSyncAll::class);
        $all->adoptWait = 1;
        $this->app->instance(BotcakeSyncAll::class, $all);

        $poll = Cache::lock(BotcakeSyncAll::LEGACY_STATE . ':adopt', 10);
        $this->assertTrue($poll->get(), 'ang poll ay umaampon ngayon');
        $this->press()->assertOk()->assertJsonPath('status', 'running');
        $poll->release();

        $this->assertSame(0, BotcakePsidImportRun::where('source', BotcakeSyncAll::SOURCE)->count(), 'walang bagong run sa tabi');
        Queue::assertNothingPushed();
        $this->press()->assertOk()->assertJsonPath('status', 'running');
        $this->assertSame(['legacy-run'], BotcakePsidImportRun::where('source', BotcakeSyncAll::SOURCE)->pluck('run_key')->all());
    }

    public function test_an_adopted_old_run_with_no_cache_key_and_no_job_is_closed(): void
    {
        config(['queue.default' => 'database']);
        Artisan::call('migrate', ['--path' => 'database/migrations/0001_01_01_000002_create_jobs_table.php', '--force' => true]);
        $job = fn (string $key) => DB::table('jobs')->insertGetId(['queue' => 'default', 'attempts' => 0, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp,
            'payload' => json_encode(['displayName' => SyncAllBotcakeCustomersJob::class, 'data' => ['command' => serialize(new SyncAllBotcakeCustomersJob($key, 2))]])]);

        // Nasa huling page ang lumang run; nabura ang cache (optimize:clear sa deploy).
        $this->legacy(2, 'Page Uno');
        $this->assertSame('running', $this->panel()['status']);
        Cache::forget(BotcakeSyncAll::LEGACY_STATE);

        $queued = $job('legacy-run');
        $this->assertSame('running', $this->panel()['status'], 'may job pa ng lumang kadena: hindi isinasara');

        DB::table('jobs')->where('id', $queued)->delete();
        $p = $this->panel();
        $this->assertSame(['done', null, 3], [$p['status'], $p['current'], $p['done']], 'tapos na ang huling page, walang job: isinara');
        $this->assertNotNull($p['finished_at']);

        // Naputol sa gitna (walang job, walang cache): itinigil, hindi "tapos".
        $this->legacy(1, 'Page Tres', 'legacy-mid');
        $this->assertSame('running', $this->panel()['status']);
        Cache::forget(BotcakeSyncAll::LEGACY_STATE);
        $this->assertSame(['stopped', 1], [$this->panel()['status'], $this->panel()['done']]);
        $this->press()->assertOk()->assertJsonPath('status', 'started');
    }
}

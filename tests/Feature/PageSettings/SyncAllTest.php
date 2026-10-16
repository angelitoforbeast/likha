<?php

namespace Tests\Feature\PageSettings;

use App\Jobs\SyncAllBotcakeCustomersJob;
use App\Models\BotcakePsidImportRun;
use App\Services\BotcakeCustomerSync;
use App\Services\BotcakeSyncAll;
use App\Services\PageSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Botcake\BotcakeApiTestCase;

/** "Sync lahat": isang incremental sync kada page, isa-isa, sa ayos ng grid; naghihintay kapag may hawak ng lock. */
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

    private function progress(): ?array
    {
        return $this->getJson('/page-settings/grid?since=' . urlencode(now()->subMinute()->format('Y-m-d H:i:s')))->assertOk()->json('sync_all');
    }

    /** @return array<int, string> ang page id ng bawat Botcake request, sa ayos ng pagpapadala */
    private function calledPages(): array
    {
        return collect(Http::recorded())->map(fn ($r) => preg_replace('#.*/pages/(\d+)/.*#', '$1', $r[0]->url()))->all();
    }

    public function test_pages_run_one_at_a_time_in_grid_order_and_a_failing_page_does_not_stop_the_next(): void
    {
        Http::fake([
            'botcake.io/api/public_api/v1/pages/3003/*' => Http::response(['message' => 'down'], 500),
            'botcake.io/api/public_api/v1/pages/*'      => Http::response([], 200),
        ]);

        $this->press()->assertOk()->assertJsonPath('status', 'started')->assertJsonPath('sync_all.total', 3);

        $this->assertSame(['2002', '3003', '3003', '3003', '3003', '1001'], $this->calledPages(), 'Dos, Tres (3 retry), Uno; walang 4004 at 5005');
        $runs = BotcakePsidImportRun::orderBy('id')->get();
        $this->assertSame(['Page Dos', 'Page Tres', 'Page Uno'], $runs->pluck('page_name')->all());
        $this->assertSame(['done', 'failed', 'done'], $runs->pluck('status')->all());

        $p = $this->progress();
        $this->assertSame([3, 3, 2, 1], [$p['total'], $p['done'], $p['ok'], $p['failed']]);
        $this->assertNotNull($p['finished_at']);
    }

    public function test_a_held_lock_makes_the_page_wait_then_run_and_the_poll_shows_the_progress(): void
    {
        Queue::fake();
        Http::fake(['botcake.io/api/public_api/v1/pages/*' => Http::response([], 200)]);
        $this->press()->assertOk();
        $first = Queue::pushed(SyncAllBotcakeCustomersJob::class)->sole();

        $held = Cache::lock(BotcakeCustomerSync::LOCK, 60);
        $this->assertTrue($held->get(), 'ang row Sync now o ang schedule ay may hawak');
        $first->handle(app(BotcakeCustomerSync::class), app(BotcakeSyncAll::class));

        Http::assertNothingSent();
        $again = Queue::pushed(SyncAllBotcakeCustomersJob::class)->last();
        $this->assertNotSame($first, $again);
        $this->assertSame(0, $again->index, 'parehong page ulit, hindi nilaktawan');
        $this->assertNotNull($again->delay);
        $p = $this->progress();
        $this->assertSame(['Page Dos', true, 0, 3], [$p['current'], $p['waiting'], $p['done'], $p['total']]);

        $held->release();
        $again->handle(app(BotcakeCustomerSync::class), app(BotcakeSyncAll::class));

        $this->assertSame(['2002'], $this->calledPages());
        $this->assertSame(1, Queue::pushed(SyncAllBotcakeCustomersJob::class)->last()->index);
        $p = $this->progress();
        $this->assertSame([1, false], [$p['done'], $p['waiting']]);

        // Kapag pinatay ang job (hal. timeout), tuloy pa rin ang susunod na page.
        Queue::pushed(SyncAllBotcakeCustomersJob::class)->last()->failed(new \RuntimeException('timeout'));
        $this->assertSame(2, Queue::pushed(SyncAllBotcakeCustomersJob::class)->last()->index);
        $this->assertSame([2, 1], [$this->progress()['done'], $this->progress()['failed']]);
    }

    public function test_a_job_whose_run_state_is_gone_logs_a_warning_and_stops(): void
    {
        Queue::fake();
        Http::fake();
        $this->press()->assertOk();
        $job = Queue::pushed(SyncAllBotcakeCustomersJob::class)->sole();
        Cache::forget(BotcakeSyncAll::STATE); // hal. cache:clear sa deploy
        \Illuminate\Support\Facades\Log::spy();

        $job->handle(app(BotcakeCustomerSync::class), app(BotcakeSyncAll::class));

        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->once()
            ->withArgs(fn ($msg, $ctx = []) => str_contains($msg, 'Sync lahat') && $ctx === ['run_id' => $job->runId, 'index' => 0]);
        Http::assertNothingSent();
        Queue::assertPushed(SyncAllBotcakeCustomersJob::class, 1);
    }

    public function test_a_second_press_during_a_run_starts_nothing(): void
    {
        Queue::fake();

        $this->press()->assertOk()->assertJsonPath('status', 'started');
        $this->press()->assertOk()->assertJsonPath('status', 'running')->assertJsonPath('sync_all.done', 0);

        Queue::assertPushed(SyncAllBotcakeCustomersJob::class, 1);
    }

    public function test_only_the_ceo_reaches_it_and_no_token_is_sent(): void
    {
        Queue::fake();

        $body = $this->press()->getContent();
        $this->assertStringNotContainsString('tok-sync-all', $body);

        $this->actingAs($this->user('Marketing', 'mkt@example.test'));
        $this->press()->assertNotFound();
        Queue::assertPushed(SyncAllBotcakeCustomersJob::class, 1);
    }
}

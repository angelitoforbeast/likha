<?php

namespace Tests\Feature\Chatblast;

use App\Jobs\ChatblastBuildJob;
use App\Jobs\SendChatblastMessage;
use App\Models\BotcakeMessage;
use App\Services\Chatblast;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Ang Dashboard list: nakatago ang skipped kapag walang napiling status, kasama sa default na "ngayong araw" ang mga
 * ready pa na nabuo noong mga naunang araw (sa loob ng window ng sequence), at ang "Fetch new" ay job sa queue.
 */
class ChatblastDashboardListTest extends ChatblastTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->user());
        $this->travelTo('2026-10-18 10:00:00');
    }

    private function msg(string $status, array $extra = []): int
    {
        return DB::table('botcake_messages')->insertGetId($extra + [
            'page_name' => 'Page Uno', 'waybill' => 'WB-' . uniqid(), 'kind' => 'delivering', 'fb_name' => 'Buyer', 'psid' => 'psid-123456',
            'text' => 'Hello Buyer', 'status' => $status, 'attempts' => 0, 'is_test' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function list(array $query = [])
    {
        $today = now('Asia/Manila')->toDateString();
        return $this->getJson('/chatblast/list?' . http_build_query($query + ['from' => $today, 'to' => $today]))->assertOk();
    }

    public function test_S_056_8_skipped_rows_are_hidden_unless_the_skipped_chip_is_chosen(): void
    {
        $this->msg('ready');
        $this->msg('sent', ['sent_at' => now()]);
        $skipped = $this->msg('skipped', ['reason' => 'already sent today by another sequence']);

        $res = $this->list();
        $this->assertSame(['ready' => 1, 'sent' => 1, 'skipped' => 1], $res->json('counts'), 'ang bilang ay may skipped pa rin');
        $this->assertSame(2, $res->json('total'));
        $this->assertNotContains('skipped', array_column($res->json('rows'), 'status'));

        $res = $this->list(['status' => 'skipped']);
        $this->assertSame(1, $res->json('total'));
        $this->assertSame([[$skipped, 'already sent today by another sequence']], array_map(fn ($r) => [$r['id'], $r['reason']], $res->json('rows')));
    }

    public function test_S_056_9_the_default_view_adds_ready_rows_built_on_earlier_days_within_the_window(): void
    {
        $today = $this->msg('ready');
        $yesterday = $this->msg('ready', ['created_at' => now()->subDay()]);
        $this->msg('ready', ['created_at' => now()->subDays(8)]);                          // labas sa 7 araw ng delivering
        $transit = $this->msg('ready', ['kind' => 'in_transit', 'created_at' => now()->subDays(10)]); // nasa 14 araw ng in_transit
        $this->msg('sent', ['created_at' => now()->subDay(), 'sent_at' => now()->subDay()]); // hindi ready

        $res = $this->list();
        $this->assertEqualsCanonicalizing([$today, $yesterday, $transit], array_column($res->json('rows'), 'id'));
        $this->assertSame(['ready' => 3], $res->json('counts'));
        $this->assertSame(2, $res->json('earlier_ready'));

        $day = now('Asia/Manila')->subDay()->toDateString();
        $res = $this->getJson('/chatblast/list?' . http_build_query(['from' => $day, 'to' => $day]))->assertOk();
        $this->assertSame(['ready' => 1, 'sent' => 1], $res->json('counts'), 'ibang petsa: eksaktong petsa ng pagbuo');
        $this->assertSame(0, $res->json('earlier_ready'));
    }

    public function test_S_056_10_send_all_ready_in_the_default_view_sends_the_earlier_ready_rows_too(): void
    {
        Queue::fake([SendChatblastMessage::class]);
        $this->msg('ready');
        $earlier = $this->msg('ready', ['created_at' => now()->subDays(2)]);
        $today = now('Asia/Manila')->toDateString();

        $this->postJson('/chatblast/send-filtered', ['from' => $today, 'to' => $today])->assertOk()->assertJsonPath('queued', 2);
        $this->assertSame('queued', BotcakeMessage::find($earlier)->status);

        $other = $this->msg('ready', ['created_at' => now()->subDays(3)]);
        $day = now('Asia/Manila')->subDay()->toDateString();
        $this->postJson('/chatblast/send-filtered', ['from' => $day, 'to' => $day])->assertOk()->assertJsonPath('queued', 0);
        $this->assertSame('ready', BotcakeMessage::find($other)->status);
    }

    public function test_S_056_11_fetch_new_runs_as_a_queued_job_one_at_a_time_and_always_frees_the_lock(): void
    {
        $this->page('Page Uno');
        $this->shipped('Page Uno', 'Ana Cruz', 'WB-001', 'psid-a');

        // Naghihintay sa worker: nakikita sa state, at ang pangalawang pindot ay 409.
        Queue::fake([ChatblastBuildJob::class]);
        $this->postJson('/chatblast/build', ['kind' => 'delivering'])->assertOk()->assertJsonPath('run.status', 'queued');
        Queue::assertPushed(ChatblastBuildJob::class, 1);
        $this->assertSame('queued', $this->list()->json('state.build.status'));
        $this->postJson('/chatblast/build', ['kind' => 'delivering'])->assertStatus(409)->assertJsonPath('error', 'A fetch is already running; try again after it.');

        // Tumakbo ang job: done, may resulta, at malaya ang lock at ang slot.
        $job = Queue::pushed(ChatblastBuildJob::class)->first();
        $job->handle(app(Chatblast::class));
        $build = $this->list()->json('state.build');
        $this->assertSame(['done', 1], [$build['status'], $build['result']['added']]);
        $this->assertTrue(($probe = Cache::lock(Chatblast::BUILD_LOCK, 5))->get(), 'malaya ang build lock');
        $probe->release();

        // May hawak ng lock ang iba (hal. chatblast:auto): 409, walang job.
        $other = Cache::lock(Chatblast::BUILD_LOCK, 60);
        $other->get();
        $this->postJson('/chatblast/build', ['kind' => 'delivering'])->assertStatus(409);
        Queue::assertPushed(ChatblastBuildJob::class, 1);

        // Ang failed() (hal. timeout ng worker) ay hindi bumibitaw sa lock ng iba...
        (new ChatblastBuildJob('delivering', null, 'token-of-a-dead-job'))->failed(new \RuntimeException('timed out'));
        $this->assertFalse(Cache::lock(Chatblast::BUILD_LOCK, 5)->get(), 'ang lock ng iba ay hawak pa');
        $other->release();

        // ...pero binibitawan ang sarili nitong lock, at failed ang ipinapakita.
        $this->postJson('/chatblast/build', ['kind' => 'delivering'])->assertOk();
        $job = Queue::pushed(ChatblastBuildJob::class)->last();
        Cache::lock(Chatblast::BUILD_LOCK, 300, $job->token)->get(); // namatay habang hawak ang lock
        $job->failed(new \RuntimeException('timed out'));
        $this->assertTrue(($probe = Cache::lock(Chatblast::BUILD_LOCK, 5))->get(), 'nabitawan ang lock ng namatay na job');
        $probe->release();
        $this->assertSame('failed', $this->list()->json('state.build.status'));
        $this->postJson('/chatblast/build', ['kind' => 'delivering'])->assertOk(); // malaya na ang slot
    }

    public function test_S_056_12_the_dashboard_says_what_the_list_covers_and_shows_the_fetch_progress(): void
    {
        $html = $this->get('/chatblast')->assertOk()->getContent();

        $this->assertStringContainsString("'all (no skipped)'", $html);
        $this->assertStringContainsString('ready from earlier days', $html);
        $this->assertStringContainsString('Fetching new messages in the background', $html);
    }
}

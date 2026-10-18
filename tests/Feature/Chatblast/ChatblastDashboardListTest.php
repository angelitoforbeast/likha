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
 * Ang "Fetch new" ng /chatblast bilang job sa queue (isa lang sabay, laging nabibitawan ang build lock). Ang mga
 * listahan (To send at History) ay nasa ChatblastToSendTest.
 */
class ChatblastDashboardListTest extends ChatblastTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->user());
        $this->travelTo('2026-10-18 10:00:00');
    }

    private function list(array $query = [])
    {
        $today = now('Asia/Manila')->toDateString();
        return $this->getJson('/chatblast/list?' . http_build_query($query + ['from' => $today, 'to' => $today]))->assertOk();
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

        // Nawala ang slot habang naghihintay pa (hal. walang worker nang 10 minuto): "stopped", at puwede nang pindutin ulit.
        Cache::forget(ChatblastBuildJob::SLOT);
        $build = $this->list()->json('state.build');
        $this->assertSame(['stopped', 'The fetch waited too long for the worker; press Fetch new again.'], [$build['status'], $build['error']]);

        // Kinuha ng ibang build ang lock bago tumakbo ang job: busy, at ang lock ng iba ay hindi ginagalaw.
        $this->postJson('/chatblast/build', ['kind' => 'delivering'])->assertOk();
        $job = Queue::pushed(ChatblastBuildJob::class)->last();
        $other = Cache::lock(Chatblast::BUILD_LOCK, 60);
        $other->get();
        $job->handle(app(Chatblast::class));
        $this->assertSame('busy', $this->list()->json('state.build.status'));
        $this->assertFalse(Cache::lock(Chatblast::BUILD_LOCK, 5)->get());
        $other->release();
    }
}

<?php

namespace Tests\Feature\Chatblast;

use App\Jobs\SendChatblastMessage;
use App\Models\BotcakeMessage;
use App\Models\ChatblastCampaign;
use App\Services\Chatblast;
use App\Services\ChatblastSender;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Auto mode: ang chatblast:auto (kada 5 minuto) ay bumubuo at nagku-queue ng campaign na auto sa bawat oras ng padala
 * nito, isang beses kada oras kada araw, hanggang 3 oras pagkatapos ng oras; wala kapag naka-off, naka-pause o manual.
 * Fake na orasan at queue; walang J&T o Botcake.
 */
class ChatblastAutoTest extends ChatblastTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::fake();
        $this->page('Page Uno');
    }

    private function campaign(array $over = []): ChatblastCampaign
    {
        return ChatblastCampaign::create($over + [
            'kind' => 'c-auto', 'name' => 'Auto', 'statuses' => ['In Transit'], 'window_days' => 10, 'days' => [['Hi {name}']],
            'requires_on_delivery' => false, 'mode' => 'auto', 'send_times' => ['09:00', '15:00'], 'active' => true,
        ]);
    }

    private function tick(string $at): void
    {
        $this->travelTo($at);
        Artisan::call('chatblast:auto');
    }

    private function statuses(string $kind = 'c-auto'): array
    {
        return BotcakeMessage::where('kind', $kind)->orderBy('waybill')->pluck('status', 'waybill')->all();
    }

    public function test_an_auto_campaign_builds_and_queues_at_each_send_time_once_a_day(): void
    {
        $c = $this->campaign();
        $this->travelTo('2026-10-18 08:00:00');
        $this->shipped('Page Uno', 'Ana Cruz', 'WB-1', 'psid-a', 'In Transit');

        $this->tick('2026-10-18 08:59:00');
        $this->assertSame([], $this->statuses(), 'hindi pa oras');
        app(Chatblast::class)->build('in_transit', 1); // ready na row ng ibang (manual) campaign ngayong araw

        $this->tick('2026-10-18 09:00:00');
        $this->assertSame(['WB-1' => 'queued'], $this->statuses());
        $this->assertSame(['WB-1' => 'ready'], $this->statuses('in_transit'), 'ang ready ng manual na campaign ay hindi kinukuha');
        Queue::assertPushed(SendChatblastMessage::class, 1);

        $this->shipped('Page Uno', 'Ben Reyes', 'WB-2', 'psid-b', 'In Transit');
        $this->tick('2026-10-18 09:05:00');
        $this->assertSame(['WB-1' => 'queued'], $this->statuses(), 'isang beses lang ang 09:00');

        $this->tick('2026-10-18 15:02:00');
        $this->assertSame(['WB-1' => 'queued', 'WB-2' => 'queued'], $this->statuses(), 'ang 15:00; ang WB-1 ay may mensahe na ngayong araw');
        Queue::assertPushed(SendChatblastMessage::class, 2);

        BotcakeMessage::where('kind', 'c-auto')->update(['status' => 'sent', 'sent_at' => now()]);
        $c->update(['days' => [['Hi {name}'], ['Day 2 {name}']]]);
        $this->tick('2026-10-19 09:01:00');
        $this->assertSame(2, BotcakeMessage::where('kind', 'c-auto')->where('day', 2)->where('status', 'queued')->count(), 'kinabukasan: Day 2');
    }

    public function test_nothing_runs_when_off_manual_paused_busy_too_late_or_switched_on_after_the_time(): void
    {
        $this->travelTo('2026-10-18 08:00:00');
        $this->shipped('Page Uno', 'Ana Cruz', 'WB-1', 'psid-a', 'In Transit');
        $this->campaign(['kind' => 'c-off', 'active' => false]);
        $this->campaign(['kind' => 'c-manual', 'mode' => 'manual']);
        $this->campaign(['kind' => 'c-late', 'send_times' => ['08:00']]);
        $this->campaign(['kind' => 'c-new', 'send_times' => ['09:00'], 'auto_ran_at' => now()->setTime(9, 30)]); // in-auto nang 09:30
        $paused = $this->campaign(['send_times' => ['09:00']]);

        app(ChatblastSender::class)->pause();
        $this->tick('2026-10-18 09:00:00');
        $this->assertNull($paused->fresh()->auto_ran_at, 'naka-pause: hindi itinuturing na tumakbo');
        app(ChatblastSender::class)->resume();

        $this->travelTo('2026-10-18 09:05:00');
        $lock = Cache::lock(Chatblast::BUILD_LOCK, 300); // pagkatapos ng travel: ang lock ng array cache ay may oras
        $lock->get();
        Artisan::call('chatblast:auto');
        $this->assertNull($paused->fresh()->auto_ran_at, 'may tumatakbong pagkuha: sa susunod na 5 minuto');
        $lock->release();

        $this->tick('2026-10-18 11:10:00');
        $this->assertSame(['WB-1' => 'queued'], $this->statuses(), 'pagkatapos ng pause, sa loob ng 3 oras');
        foreach (['c-off', 'c-manual', 'c-late', 'c-new'] as $kind) $this->assertSame([], $this->statuses($kind), $kind);
    }

    public function test_S_056_13_a_ready_row_from_before_the_switch_is_sent_once_auto_s_fetch_re_checks_it(): void
    {
        $this->travelTo('2026-10-17 08:00:00');
        $this->campaign(['send_times' => ['09:00', '15:00'], 'mode' => 'manual']);
        $this->shipped('Page Uno', 'Ana Cruz', 'WB-1', 'psid-a', 'In Transit');
        app(Chatblast::class)->build('c-auto', 1); // kahapon: ready, hindi naipadala
        $this->travelTo('2026-10-17 20:00:00');
        ChatblastCampaign::where('kind', 'c-auto')->update(['mode' => 'auto', 'mode_changed_at' => now(), 'auto_ran_at' => now()]);

        $this->travelTo('2026-10-18 08:00:00');
        $this->shipped('Page Uno', 'Ben Reyes', 'WB-2', 'psid-b', 'In Transit');
        $this->tick('2026-10-18 09:00:00');

        // In Transit pa ang WB-1: nasuri ulit ng fetch ng auto bago magpadala, kaya ipinadala kasama ng bago.
        $this->assertSame(['WB-1' => 'queued', 'WB-2' => 'queued'], $this->statuses());
        $this->assertSame('2026-10-17', BotcakeMessage::where('waybill', 'WB-1')->sole()->created_at->toDateString(), 'ang dating row, hindi bagong row');
    }

    public function test_S_056_14_a_ready_row_whose_waybill_moved_on_is_skipped_frees_its_key_and_the_next_sequence_builds(): void
    {
        $this->travelTo('2026-10-17 08:00:00');
        $this->campaign(['mode' => 'manual']);
        $this->campaign(['kind' => 'c-deliv', 'name' => 'Deliv', 'statuses' => ['Delivering'], 'mode' => 'manual']);
        $this->shipped('Page Uno', 'Ana Cruz', 'WB-1', 'psid-a', 'In Transit');
        app(Chatblast::class)->build('c-auto', 1);

        $this->travelTo('2026-10-18 08:00:00');
        DB::table('from_jnts')->where('waybill_number', 'WB-1')->update(['status' => 'Delivering']);
        app(Chatblast::class)->build('c-auto', 1);
        app(Chatblast::class)->build('c-deliv', 1);

        $old = BotcakeMessage::where('kind', 'c-auto')->sole();
        $this->assertSame(['skipped', 'The waybill is no longer In Transit (now: Delivering)', null], [$old->status, $old->reason, $old->dedupe_key]);
        $this->assertSame(['WB-1' => 'ready'], $this->statuses('c-deliv'));
    }

    public function test_S_056_15_auto_does_not_send_a_row_nobody_re_checked_since_before_the_switch(): void
    {
        $this->travelTo('2026-10-17 08:00:00');
        $this->campaign(['mode' => 'manual']);
        $this->shipped('Page Uno', 'Ana Cruz', 'WB-1', 'psid-a', 'In Transit');
        app(Chatblast::class)->build('c-auto', 1); // kahapon, at hindi na nasuri ulit
        $this->travelTo('2026-10-17 20:00:00');
        ChatblastCampaign::where('kind', 'c-auto')->update(['mode' => 'auto', 'mode_changed_at' => now(), 'auto_ran_at' => now()]);

        $this->travelTo('2026-10-18 08:30:00');
        $this->shipped('Page Uno', 'Ben Reyes', 'WB-2', 'psid-b', 'In Transit');
        DB::table('botcake_messages')->insert(['page_name' => 'Page Uno', 'waybill' => 'WB-2', 'kind' => 'c-auto', 'day' => 1, 'psid' => 'psid-b',
            'text' => 'Hi Ben', 'status' => 'ready', 'attempts' => 0, 'is_test' => false, 'dedupe_key' => 'WB-2|c-auto|1', 'created_at' => now(), 'updated_at' => now()]);

        // Ang fetch ng auto ay hindi nakasuri (hal. walang naabot): ang hindi nasuri mula bago i-auto ay hindi ipinapadala.
        $this->partialMock(Chatblast::class, fn ($m) => $m->shouldReceive('build')->andReturn(['added' => 0]));
        $this->tick('2026-10-18 09:00:00');

        $this->assertSame(['WB-1' => 'ready', 'WB-2' => 'queued'], $this->statuses());
    }

    public function test_the_scheduler_runs_it_every_five_minutes(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'chatblast:auto'));
        $this->assertNotNull($event);
        $this->assertSame('*/5 * * * *', $event->expression);
    }
}

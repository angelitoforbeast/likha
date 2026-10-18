<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeMessage;
use App\Models\ChatblastCampaign;
use App\Services\ChatblastSchedule;
use App\Services\ChatblastSender;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Ang schedule ng campaign: mga araw ng linggo, ilang oras, oras ng isang step, ang susunod na takbo, ang buod, at ang
 * cap ng campaign kada page. 2026-10-18 ay Linggo, 2026-10-19 ay Lunes. Fake na orasan at queue; walang Botcake o J&T.
 */
class ChatblastScheduleTest extends ChatblastTestCase
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
            'kind' => 'c-auto', 'name' => 'Auto', 'statuses' => ['In Transit'], 'window_days' => 10, 'days' => [['Una {name}'], ['Pangalawa {name}']],
            'requires_on_delivery' => false, 'mode' => 'auto', 'send_times' => ['09:00'], 'active' => true,
        ]);
    }

    private function tick(string $at): void
    {
        $this->travelTo($at);
        Artisan::call('chatblast:auto');
    }

    private function rows(): array
    {
        return BotcakeMessage::where('kind', 'c-auto')->orderBy('waybill')->orderBy('day')->get()
            ->map(fn ($r) => $r->waybill . ' D' . $r->day . ' ' . $r->status)->all();
    }

    public function test_auto_runs_only_on_the_chosen_weekdays(): void
    {
        $this->campaign(['weekdays' => [1]]); // Lunes lang
        $this->travelTo('2026-10-18 08:00:00');
        $this->shipped('Page Uno', 'Ana Cruz', 'WB-1', 'psid-a', 'In Transit');

        $this->tick('2026-10-18 09:00:00'); // Linggo
        $this->assertSame([], $this->rows());
        $this->tick('2026-10-19 09:00:00'); // Lunes
        $this->assertSame(['WB-1 D1 queued'], $this->rows());
    }

    public function test_a_step_with_its_own_time_is_built_and_sent_at_that_time_only(): void
    {
        $this->campaign(['send_times' => ['09:00', '18:00'], 'step_times' => [null, ['15:00']]]);
        $this->travelTo('2026-10-18 08:00:00');
        $this->shipped('Page Uno', 'Ana Cruz', 'WB-1', 'psid-a', 'In Transit');
        $this->shipped('Page Uno', 'Ben Reyes', 'WB-2', 'psid-b', 'In Transit');
        DB::table('botcake_messages')->insert(['page_name' => 'Page Uno', 'waybill' => 'WB-1', 'kind' => 'c-auto', 'day' => 1, 'psid' => 'psid-a',
            'text' => 'x', 'jnt_status' => 'In Transit', 'status' => 'sent', 'sent_at' => now()->subDay(), 'dedupe_key' => 'WB-1|c-auto|1',
            'attempts' => 0, 'is_test' => false, 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);

        $this->tick('2026-10-18 09:00:00');
        $this->assertSame(['WB-1 D1 sent', 'WB-2 D1 queued'], $this->rows(), '09:00: Day 1 lang; ang Day 2 ay sa 15:00');

        $this->tick('2026-10-18 15:00:00');
        $this->assertSame(['WB-1 D1 sent', 'WB-1 D2 queued', 'WB-2 D1 queued'], $this->rows());
        $this->tick('2026-10-18 15:05:00');
        Queue::assertPushed(\App\Jobs\SendChatblastMessage::class, 2);

        $this->shipped('Page Uno', 'Cora Diaz', 'WB-3', 'psid-c', 'In Transit');
        $this->tick('2026-10-18 18:00:00');
        $this->assertSame(['WB-1 D1 sent', 'WB-1 D2 queued', 'WB-2 D1 queued', 'WB-3 D1 queued'], $this->rows(), '18:00: ang ikalawang oras ng campaign');
    }

    public function test_the_next_run_and_the_summary_follow_mode_weekdays_times_and_step_times(): void
    {
        $s = app(ChatblastSchedule::class);
        $now = Carbon::parse('2026-10-18 09:30:00', 'Asia/Manila'); // Linggo
        $c = $this->campaign(['send_times' => ['09:15', '15:00'], 'step_times' => [['10:00'], null]]);

        $this->assertSame('Araw-araw, 9:15 AM at 3:00 PM; Day 1 tuwing 10:00 AM', $s->summary($c));
        $this->assertSame('2026-10-18 10:00', $s->nextRun($c, $now)?->format('Y-m-d H:i'));
        $c->weekdays = [1, 3];
        $this->assertSame('Lun, Miy, 9:15 AM at 3:00 PM; Day 1 tuwing 10:00 AM', $s->summary($c));
        $this->assertSame('2026-10-19 09:15', $s->nextRun($c, $now)?->format('Y-m-d H:i'));
        $c->mode = 'manual';
        $this->assertNull($s->nextRun($c, $now));
        $this->assertStringStartsWith('Mano-mano', $s->summary($c));
        $c->mode = 'auto';
        $c->active = false;
        $this->assertNull($s->nextRun($c, $now));
    }

    public function test_an_hourly_range_expands_with_an_inclusive_end_and_reads_as_bawat_oras(): void
    {
        $s = app(ChatblastSchedule::class);
        $c = $this->campaign(['send_times' => [], 'send_range' => ['start' => '09:00', 'end' => '21:00', 'every' => 1]]);
        $this->assertSame(['09:00', '10:00', '11:00', '12:00', '13:00', '14:00', '15:00', '16:00', '17:00', '18:00', '19:00', '20:00', '21:00'], $s->allTimes($c));
        $this->assertSame('Araw-araw, Bawat oras, 9 AM-9 PM', $s->summary($c));

        $c->send_range = ['start' => '05:00', 'end' => '16:00', 'every' => 2];
        $this->assertSame(['05:00', '07:00', '09:00', '11:00', '13:00', '15:00'], $s->allTimes($c), 'ang dulo ay kasama kapag tumapat lang');
        $this->assertSame('Araw-araw, Bawat 2 oras, 5 AM-3 PM', $s->summary($c), 'ang huling totoong oras');

        $c->send_range = ['start' => '09:30', 'end' => '11:30', 'every' => 1];
        $c->send_times = ['08:00', '10:30'];
        $this->assertSame(['08:00', '09:30', '10:30', '11:30'], $s->allTimes($c), 'kasama ang listahan, walang doble');
        $this->assertSame('Araw-araw, 8:00 AM at 10:30 AM; Bawat oras, 9:30 AM-11:30 AM', $s->summary($c));
    }

    public function test_auto_fires_hourly_inside_the_range_only_and_once_per_hour(): void
    {
        $this->campaign(['days' => [['Una {name}']], 'send_times' => [], 'send_range' => ['start' => '09:00', 'end' => '11:00', 'every' => 1]]);
        $this->travelTo('2026-10-18 08:00:00');
        $this->shipped('Page Uno', 'Ana Cruz', 'WB-1', 'psid-a', 'In Transit');

        $this->tick('2026-10-18 08:59:00');
        $this->assertSame([], $this->rows());
        $this->tick('2026-10-18 09:00:00');
        $this->assertSame(['WB-1 D1 queued'], $this->rows());

        $this->shipped('Page Uno', 'Ben Reyes', 'WB-2', 'psid-b', 'In Transit');
        $this->tick('2026-10-18 09:30:00');
        $this->assertSame(['WB-1 D1 queued'], $this->rows(), 'wala pang 10:00');
        $this->tick('2026-10-18 10:00:00');
        $this->tick('2026-10-18 10:05:00');
        $this->assertSame(['WB-1 D1 queued', 'WB-2 D1 queued'], $this->rows());
        Queue::assertPushed(\App\Jobs\SendChatblastMessage::class, 2);

        $this->shipped('Page Uno', 'Cora Diaz', 'WB-3', 'psid-c', 'In Transit');
        $this->tick('2026-10-18 11:00:00');
        $this->shipped('Page Uno', 'Dan Lim', 'WB-4', 'psid-d', 'In Transit');
        $this->tick('2026-10-18 12:00:00');
        $this->tick('2026-10-18 13:00:00');
        $this->assertSame(['WB-1 D1 queued', 'WB-2 D1 queued', 'WB-3 D1 queued'], $this->rows(), 'ang 11:00 ang huli; walang 12:00');
    }

    public function test_adding_a_past_time_does_not_cancel_an_earlier_time_that_is_still_due(): void
    {
        $this->actingAs($this->user());
        $c = $this->campaign(['days' => [['Una {name}']], 'send_times' => ['09:00'], 'auto_ran_at' => Carbon::parse('2026-10-18 08:00:00', 'Asia/Manila')]);
        $this->travelTo('2026-10-18 08:30:00');
        $this->shipped('Page Uno', 'Ana Cruz', 'WB-1', 'psid-a', 'In Transit');

        $this->travelTo('2026-10-18 09:02:00'); // hindi pa tumatakbo ang cron para sa 09:00
        $this->postJson('/chatblast/sequence/' . $c->id, ['send_times' => ['09:00', '09:01'], 'old' => ['send_times' => ['09:00']]])->assertOk();
        $this->tick('2026-10-18 09:05:00');
        $this->assertSame(['WB-1 D1 queued'], $this->rows(), 'tumakbo pa rin ang 09:00');

        $this->shipped('Page Uno', 'Ben Reyes', 'WB-2', 'psid-b', 'In Transit');
        $this->tick('2026-10-18 09:10:00');
        $this->assertSame(['WB-1 D1 queued'], $this->rows(), 'ang idinagdag na 09:01 ay hindi tumatakbo nang huli');
        $this->assertSame(['09:00', '09:01'], app(ChatblastSchedule::class)->ranToday($c->fresh(), now()));

        $this->tick('2026-10-19 09:01:00');
        $this->assertContains('WB-2 D1 queued', $this->rows(), 'kinabukasan: bagong araw ng talaan');
    }

    public function test_a_row_of_a_step_with_its_own_time_that_becomes_ready_later_is_sent_at_the_next_time(): void
    {
        $this->campaign(['send_times' => ['09:00', '18:00'], 'step_times' => [null, ['15:00']]]);
        $this->travelTo('2026-10-18 08:00:00');
        $order = $this->shipped('Page Uno', 'Ana Cruz', 'WB-1', null, 'In Transit');
        DB::table('botcake_messages')->insert(['page_name' => 'Page Uno', 'waybill' => 'WB-1', 'kind' => 'c-auto', 'day' => 1, 'psid' => 'psid-a',
            'text' => 'x', 'jnt_status' => 'In Transit', 'status' => 'sent', 'sent_at' => now()->subDay(), 'dedupe_key' => 'WB-1|c-auto|1',
            'attempts' => 0, 'is_test' => false, 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);

        $this->tick('2026-10-18 15:00:00');
        $this->assertSame(['WB-1 D1 sent', 'WB-1 D2 no_psid'], $this->rows());
        $order->update(['botcake_psid' => 'psid-a']); // natugma pagkatapos ng 15:00
        $this->tick('2026-10-18 18:00:00');
        $this->assertSame(['WB-1 D1 sent', 'WB-1 D2 queued'], $this->rows(), 'ang Day 2 (15:00 na) ay ipinadala sa susunod na oras ngayong araw');
    }

    public function test_a_ready_row_of_a_step_is_never_sent_before_that_steps_own_time(): void
    {
        $c = $this->campaign(['send_times' => ['09:00'], 'step_times' => [null, ['15:00']], 'mode_changed_at' => Carbon::parse('2026-10-18 07:00:00', 'Asia/Manila')]);
        $this->travelTo('2026-10-18 08:00:00');
        $this->shipped('Page Uno', 'Ana Cruz', 'WB-1', 'psid-a', 'In Transit');
        DB::table('botcake_messages')->insert(['page_name' => 'Page Uno', 'waybill' => 'WB-1', 'kind' => 'c-auto', 'day' => 1, 'psid' => 'psid-a',
            'text' => 'x', 'jnt_status' => 'In Transit', 'status' => 'sent', 'sent_at' => now()->subDay(), 'dedupe_key' => 'WB-1|c-auto|1',
            'attempts' => 0, 'is_test' => false, 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
        app(\App\Services\Chatblast::class)->build('c-auto', 1); // mano-manong kuha: may ready na Day 2 bago ang 15:00
        $this->assertSame(['WB-1 D1 sent', 'WB-1 D2 ready'], $this->rows());

        $this->tick('2026-10-18 09:00:00');
        $this->assertSame(['WB-1 D1 sent', 'WB-1 D2 ready'], $this->rows(), 'ang 09:00 ay hindi oras ng Day 2');
        $this->tick('2026-10-18 15:00:00');
        $this->assertSame(['WB-1 D1 sent', 'WB-1 D2 queued'], $this->rows());
    }

    public function test_a_late_tick_inside_the_range_runs_once_for_the_hours_it_missed(): void
    {
        $c = $this->campaign(['days' => [['Una {name}']], 'send_times' => [], 'send_range' => ['start' => '09:00', 'end' => '21:00', 'every' => 1],
            'auto_ran_at' => Carbon::parse('2026-10-18 11:00:00', 'Asia/Manila')]);
        $this->travelTo('2026-10-18 10:00:00');
        $this->shipped('Page Uno', 'Ana Cruz', 'WB-1', 'psid-a', 'In Transit');

        $this->tick('2026-10-18 14:30:00'); // walang takbo nang 12:00 at 13:00
        $this->assertSame(['WB-1 D1 queued'], $this->rows());
        $this->assertTrue($c->fresh()->auto_ran_at->eq(Carbon::parse('2026-10-18 14:00:00', 'Asia/Manila')), 'isang takbo, tatak ng pinakahuling oras');
        Queue::assertPushed(\App\Jobs\SendChatblastMessage::class, 1);
        $this->tick('2026-10-18 14:35:00');
        Queue::assertPushed(\App\Jobs\SendChatblastMessage::class, 1);
    }

    public function test_a_waybill_messaged_in_transit_can_get_the_delivering_message_the_same_day_but_nothing_more(): void
    {
        $this->travelTo('2026-10-18 09:00:00');
        $this->shipped('Page Uno', 'Ana Cruz', 'WB-1', 'psid-a', 'In Transit');
        app(\App\Services\Chatblast::class)->build('in_transit', 1);
        BotcakeMessage::where('kind', 'in_transit')->update(['status' => 'sent', 'sent_at' => now()]);

        $this->travelTo('2026-10-18 14:00:00');
        DB::table('from_jnts')->where('waybill_number', 'WB-1')->update(['status' => 'Delivering']);
        $this->campaign(['kind' => 'c-dlv2', 'statuses' => ['Delivering'], 'mode' => 'manual']); // isa pang sequence ng Delivering
        app(\App\Services\Chatblast::class)->build(null, 1);
        $delivering = BotcakeMessage::where('kind', 'delivering')->sole();
        $other = BotcakeMessage::where('kind', 'c-dlv2')->sole();

        BotcakeMessage::whereKey($delivering->id)->update(['status' => 'queued']);
        app(ChatblastSender::class)->process($delivering->id);
        $this->assertNotSame(\App\Services\Chatblast::CROSS, $delivering->fresh()->reason, 'ibang status: puwede ngayong araw');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'botcake.io'));

        $delivering->fresh()->update(['status' => 'sent', 'sent_at' => now()]);
        $this->travel(30)->seconds();
        BotcakeMessage::whereKey($other->id)->update(['status' => 'queued']);
        app(ChatblastSender::class)->process($other->id);
        $this->assertSame(['skipped', \App\Services\Chatblast::CROSS], [$other->fresh()->status, $other->fresh()->reason], 'parehong Delivering: wala nang iba ngayong araw');
    }

    public function test_the_campaign_cap_per_page_skips_the_rest_of_the_day(): void
    {
        $this->campaign(['mode' => 'manual', 'daily_cap' => 1]);
        $this->travelTo('2026-10-18 09:00:00');
        $this->shipped('Page Uno', 'Ana Cruz', 'WB-1', 'psid-a', 'In Transit');
        $this->shipped('Page Uno', 'Ben Reyes', 'WB-2', 'psid-b', 'In Transit');
        app(\App\Services\Chatblast::class)->build('c-auto', 1);
        [$one, $two] = BotcakeMessage::where('kind', 'c-auto')->orderBy('waybill')->get()->all();
        $one->update(['status' => 'sent', 'sent_at' => now()]);
        $two->update(['status' => 'queued']);

        app(ChatblastSender::class)->process($two->id);
        $this->assertSame(['skipped', 'Naabot ang daily cap ng campaign sa page (1 ngayong araw); ipadala ulit bukas', null],
            [$two->fresh()->status, $two->fresh()->reason, $two->fresh()->dedupe_key]);
        Http::assertNothingSent();

        // Ngayong araw: walang bagong row (ang cap ay hanggang bukas); bukas: may bagong row ang waybill.
        app(\App\Services\Chatblast::class)->build('c-auto', 1);
        $this->assertCount(1, BotcakeMessage::where('kind', 'c-auto')->where('waybill', 'WB-2')->get(), 'walang bagong row ngayong araw');
        $this->travel(1)->days();
        app(\App\Services\Chatblast::class)->build('c-auto', 1);
        $this->assertSame(['skipped', 'ready'], BotcakeMessage::where('kind', 'c-auto')->where('waybill', 'WB-2')->orderBy('id')->pluck('status')->all());
    }
}

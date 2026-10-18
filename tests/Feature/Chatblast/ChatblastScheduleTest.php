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

        // Bukas: may bagong row ang waybill (hindi naiipit sa key ng nilaktawan).
        $this->travel(1)->days();
        app(\App\Services\Chatblast::class)->build('c-auto', 1);
        $this->assertSame(['skipped', 'ready'], BotcakeMessage::where('kind', 'c-auto')->where('waybill', 'WB-2')->orderBy('id')->pluck('status')->all());
    }
}

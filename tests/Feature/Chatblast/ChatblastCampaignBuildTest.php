<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeMessage;
use App\Models\ChatblastCampaign;
use App\Services\Chatblast;
use App\Services\ChatblastSender;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Ang pagbuo kada campaign: ang mga J&T status na nagti-trigger, ang mga page, ang window, isang mensahe kada waybill
 * kada campaign kada araw, ang bilang ng araw kada campaign, ang paghinto, at ang naka-off na campaign.
 */
class ChatblastCampaignBuildTest extends ChatblastTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(); // walang J&T config: walang tracking; walang Botcake na tatawagin
        $this->page('Page Uno');
        $this->page('Page Dos', '1002');
        $this->travelTo('2026-10-18 09:00:00');
    }

    private function campaign(array $over = []): ChatblastCampaign
    {
        return ChatblastCampaign::create($over + [
            'kind' => 'c-test', 'name' => 'Problema', 'statuses' => ['Problematic', 'For Return'], 'pages' => null, 'window_days' => 10,
            'days' => [['Hi {name}, {waybill}']], 'requires_on_delivery' => false, 'mode' => 'manual', 'active' => true,
        ]);
    }

    private function rows(string $kind)
    {
        return BotcakeMessage::where('kind', $kind)->where('is_test', false)->orderBy('waybill')->orderBy('id')->get();
    }

    public function test_a_campaign_takes_the_waybills_in_any_of_its_statuses_and_skips_them_when_they_leave(): void
    {
        $c = $this->campaign();
        $this->shipped('Page Uno', 'Ana Cruz', 'WB-P', 'psid-a', 'Problematic');
        $this->shipped('Page Uno', 'Ben Reyes', 'WB-R', 'psid-b', 'For Return');
        DB::table('from_jnts')->where('waybill_number', 'WB-R')->update(['rts_reason' => 'Tumanggi ang customer']); // kasama pa rin
        $this->shipped('Page Uno', 'Cora Diaz', 'WB-T', 'psid-c', 'In Transit');

        $this->assertSame(2, app(Chatblast::class)->build('c-test', 1)['added']);
        $rows = $this->rows('c-test');
        $this->assertSame([['WB-P', 'Problematic', $c->id, 'WB-P|c-test|1', 'Hi Ana Cruz, WB-P'], ['WB-R', 'For Return', $c->id, 'WB-R|c-test|1', 'Hi Ben Reyes, WB-R']],
            $rows->map(fn ($r) => [$r->waybill, $r->jnt_status, $r->campaign_id, $r->dedupe_key, $r->text])->all());

        DB::table('from_jnts')->where('waybill_number', 'WB-P')->update(['status' => 'Delivered']);
        $this->travel(1)->days();
        app(Chatblast::class)->build('c-test', 1);
        $this->assertSame(['skipped', 'Hindi na Problematic/For Return ang waybill (ngayon: Delivered)'],
            [$this->rows('c-test')->firstWhere('waybill', 'WB-P')->status, $this->rows('c-test')->firstWhere('waybill', 'WB-P')->reason]);
    }

    public function test_only_the_chosen_pages_and_orders_inside_the_window_are_taken(): void
    {
        $this->campaign(['pages' => ['  page uno ']]); // ang pangalan ng page ay inihahambing nang trim at walang pagkakaiba sa laki ng titik
        $this->shipped('Page Uno', 'Ana Cruz', 'WB-1', 'psid-a', 'Problematic', 10);
        $this->shipped('Page Dos', 'Ben Reyes', 'WB-2', 'psid-b', 'Problematic');
        $this->shipped('Page Uno', 'Cora Diaz', 'WB-3', 'psid-c', 'Problematic', 11);

        app(Chatblast::class)->build('c-test', 1);
        $this->assertSame(['WB-1'], $this->rows('c-test')->pluck('waybill')->all());
    }

    public function test_two_campaigns_on_one_waybill_each_send_once_a_day_with_their_own_day_count(): void
    {
        $this->campaign(['kind' => 'c-two', 'statuses' => ['In Transit'], 'days' => [['Una {waybill}'], ['Pangalawa {waybill}']]]);
        $this->shipped('Page Uno', 'Ana Cruz', 'WB-1', 'psid-a', 'In Transit');

        $this->assertSame(2, app(Chatblast::class)->build(null, 1)['added'], 'isa kada campaign');
        $this->assertSame(0, app(Chatblast::class)->build(null, 1)['added'], 'isa lang kada araw');
        $this->assertSame(['WB-1|c-two|1', 'WB-1|in_transit|1'], BotcakeMessage::orderBy('dedupe_key')->pluck('dedupe_key')->all());

        $this->rows('c-two')->sole()->update(['status' => 'sent', 'sent_at' => now()]);
        $this->travel(1)->days();
        app(Chatblast::class)->build(null, 1);
        $this->assertSame([[1, 'Una WB-1'], [2, 'Pangalawa WB-1']], $this->rows('c-two')->map(fn ($r) => [$r->day, $r->text])->all());
        $this->travel(1)->days();
        $this->rows('c-two')->last()->update(['status' => 'sent', 'sent_at' => now()]);
        $this->travel(1)->days();
        app(Chatblast::class)->build(null, 1);
        $this->assertCount(2, $this->rows('c-two'), 'ubos na ang dalawang araw');
    }

    public function test_an_off_campaign_builds_nothing_and_its_queued_row_is_not_sent(): void
    {
        Queue::fake(); // ang job ay tatakbo sa ibaba, pagkatapos i-off
        $this->shipped('Page Uno', 'Ana Cruz', 'WB-1', 'psid-a', 'In Transit');
        app(Chatblast::class)->build('in_transit', 1);
        $row = $this->rows('in_transit')->sole();
        app(ChatblastSender::class)->queue([$row->id], 1);

        ChatblastCampaign::where('kind', 'in_transit')->update(['active' => false]);
        $this->shipped('Page Uno', 'Ben Reyes', 'WB-2', 'psid-b', 'In Transit');
        $this->assertSame(0, app(Chatblast::class)->build('in_transit', 1)['added']);
        $this->assertSame(0, app(Chatblast::class)->build(null, 1)['added']);

        app(ChatblastSender::class)->process($row->id); // ang job na naka-queue na bago in-off
        $this->assertSame(['skipped', ChatblastSender::OFF], [$row->fresh()->status, $row->fresh()->reason]);
        Http::assertNothingSent();
    }
}

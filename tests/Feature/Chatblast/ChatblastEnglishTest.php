<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeMessage;
use App\Models\ChatblastCampaign;
use App\Services\Chatblast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Ang tatlong page ng chatblast ay English (ang mga pamagat, button, at mensahe ng error), at ang mga row na may dating
 * (Tagalog) na dahilan ay gumagana pa rin gaya ng dati.
 */
class ChatblastEnglishTest extends ChatblastTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        $this->actingAs($this->user());
        $this->travelTo('2026-10-18 10:00:00');
        $this->page('Page Uno');
    }

    public function test_the_sending_page_is_in_english(): void
    {
        $this->get('/chatblast')->assertOk()
            ->assertSee('Fetch new')->assertSee('Send all ready')->assertSee('Retry failed')->assertSee('Messages')->assertSee('Sequences')
            ->assertSee('No messages in this filter')->assertSee('aria-label="Close"', false)
            ->assertDontSee('Kunin ang mga bago')->assertDontSee('Mga mensahe')->assertDontSee('Hanapin')->assertDontSee('Ipadala');
    }

    public function test_the_messages_page_is_in_english(): void
    {
        $this->get('/chatblast/campaigns')->assertOk()
            ->assertSee('<h1 class="text-2xl font-semibold">Messages</h1>', false)->assertSee('+ New message')->assertSee('Flow and template (per page)')
            ->assertSee('Placeholders')->assertSee('sent (last ')->assertSee('What the customer sees')
            ->assertDontSee('Mga mensahe')->assertDontSee('Bagong mensahe')->assertDontSee('naipadala')->assertDontSee('alisin');
    }

    public function test_the_sequences_page_is_in_english(): void
    {
        $this->get('/chatblast/sequence')->assertOk()
            ->assertSee('<h1 class="text-2xl font-semibold">Sequences</h1>', false)->assertSee('+ New sequence')->assertSee('Create (manual, on)')
            ->assertSee("'Mon'", false)->assertSee('Every N hours')->assertSee('Send times')->assertSee('Switch')
            ->assertDontSee("row('Bawat oras'", false)->assertDontSee('Mga sequence')->assertDontSee("'Lun'", false)->assertDontSee('Gawing AUTO');
        $c = collect($this->getJson('/chatblast/sequence/data')->json('campaigns'))->firstWhere('kind', 'in_transit');
        $this->assertSame('Manual (times are not followed). Every day, 9:00 AM', $c['summary']);
    }

    public function test_a_validation_error_from_the_sequence_update_is_in_english(): void
    {
        $c = ChatblastCampaign::where('kind', 'in_transit')->sole();
        $err = fn (array $body) => $this->postJson('/chatblast/sequence/' . $c->id, $body)->assertStatus(422)->json('error');
        $this->assertSame('Days: pick at least one (Mon to Sun).', $err(['weekdays' => []]));
        $this->assertSame('Window: 1 to 60 days.', $err(['window_days' => 0]));
        $this->assertSame('Every N hours: the end must be after the start (on the same day).', $err(['send_range' => ['start' => '21:00', 'end' => '09:00']]));
        $this->assertSame('Confirm first: auto sends by itself at the sequence times.', $err(['mode' => 'auto']));
    }

    public function test_old_no_config_and_no_psid_rows_with_the_same_reason_in_tagalog_are_not_rewritten_or_counted(): void
    {
        $this->page('Page Dos', '1002', ['flow.shipping', 'field.message']);
        $noPsid = $this->shipped('Page Uno', 'Ana Cruz', 'WB-1', null, 'In Transit');
        $noConfig = $this->shipped('Page Dos', 'Ben Reyes', 'WB-2', 'psid-b', 'In Transit');
        $yesterday = now()->subDay();
        $row = fn ($order, string $page, string $status, ?string $psid, string $reason) => DB::table('botcake_messages')->insertGetId([
            'page_name' => $page, 'macro_output_id' => $order->id, 'waybill' => $order->waybill, 'kind' => 'in_transit', 'day' => 1, 'psid' => $psid,
            'text' => 'x', 'jnt_status' => 'In Transit', 'status' => $status, 'reason' => $reason, 'attempts' => 0, 'is_test' => false,
            'dedupe_key' => $order->waybill . '|in_transit|1', 'created_at' => $yesterday, 'updated_at' => $yesterday]);
        $a = $row($noPsid, 'Page Uno', 'no_psid', null, 'walang PSID ang order (hindi pa tugma sa Botcake customer)');
        $b = $row($noConfig, 'Page Dos', 'no_config', 'psid-b', 'walang flow.shipping, field.message');

        $this->assertSame(0, app(Chatblast::class)->build('in_transit', 1)['updated'], 'parehong dahilan: hindi bilang');
        $this->assertSame(['walang PSID ang order (hindi pa tugma sa Botcake customer)', 'walang flow.shipping, field.message'],
            [BotcakeMessage::find($a)->reason, BotcakeMessage::find($b)->reason], 'hindi isinulat ulit');
        $this->assertTrue(BotcakeMessage::find($a)->updated_at->eq($yesterday) && BotcakeMessage::find($b)->updated_at->eq($yesterday));

        // Ibang dahilan na (hal. kulang na lang ang field.message): isinusulat, sa English.
        app(\App\Services\PageSettings::class)->set('Page Dos', 'botcake', 'flow.shipping', '5501');
        $this->assertSame(1, app(Chatblast::class)->build('in_transit', 1)['updated']);
        $this->assertSame('missing field.message', BotcakeMessage::find($b)->reason);
    }

    public function test_old_tagalog_reasons_come_out_english_in_the_list_and_the_panel_without_rewriting_the_rows(): void
    {
        $old = [
            'walang page_id, token, flow.shipping, field.message' => 'missing page_id, token, flow.shipping, field.message',
            'walang PSID ang order (hindi pa tugma sa Botcake customer)' => 'the order has no PSID (not matched to a Botcake customer yet)',
            'walang rider info' => 'no rider info',
            'walang {cod}' => 'missing {cod}',
            'Hindi na In Transit ang waybill (ngayon: Delivered)' => 'The waybill is no longer In Transit (now: Delivered)',
            'Wala na ang page na Page Dos sa campaign' => 'Page "Page Dos" is no longer in the sequence',
            'may naipadala na ngayong araw sa ibang campaign' => 'already sent today by another sequence',
            'Naabot ang daily cap ng campaign sa page (5 ngayong araw); ipadala ulit bukas' => 'Sequence daily cap reached for the page (5 today); send again tomorrow',
            'Hindi tiyak kung naipadala (tinawag na ang send_flow) (HTTP 503); tingnan sa Botcake bago magpadala ulit' => 'Not sure it was sent (send_flow was called) (HTTP 503); check Botcake before sending again',
            'Error: hindi kilala' => 'Error: hindi kilala', // hindi kilalang anyo: ganoon pa rin
        ];
        $ids = [];
        foreach (array_keys($old) as $i => $reason) {
            $ids[$reason] = DB::table('botcake_messages')->insertGetId(['page_name' => 'Page Uno', 'waybill' => 'WB-' . $i, 'kind' => 'in_transit', 'day' => 1,
                'text' => 'Kumusta po {name}', 'status' => 'skipped', 'reason' => $reason, 'attempts' => 0, 'is_test' => false, 'created_at' => now(), 'updated_at' => now()]);
        }

        $rows = collect($this->getJson('/chatblast/list')->assertOk()->json('rows'))->pluck('reason', 'id');
        foreach ($old as $reason => $english) {
            $this->assertSame($english, $rows[$ids[$reason]], $reason);
            $this->assertSame($reason, BotcakeMessage::find($ids[$reason])->reason, 'ang row ay hindi isinulat');
        }
        $panel = $this->getJson('/chatblast/messages/' . $ids['walang rider info'])->assertOk();
        $this->assertSame(['no rider info', 'Kumusta po {name}'], [$panel->json('reason'), $panel->json('text')], 'ang text ng customer ay ganoon pa rin');
    }

    public function test_rows_with_the_old_reasons_still_behave_the_same(): void
    {
        $this->shipped('Page Uno', 'Ana Cruz', 'WB-1', 'psid-a', 'In Transit');
        $this->shipped('Page Uno', 'Ben Reyes', 'WB-2', 'psid-b', 'In Transit');
        $row = fn (string $wb, string $status, string $reason) => DB::table('botcake_messages')->insert(['page_name' => 'Page Uno', 'waybill' => $wb,
            'kind' => 'in_transit', 'day' => 1, 'psid' => 'psid-x', 'text' => 'x', 'jnt_status' => 'In Transit', 'status' => $status, 'reason' => $reason,
            'attempts' => 0, 'is_test' => false, 'created_at' => now(), 'updated_at' => now()]);
        $row('WB-1', 'skipped', Chatblast::LEGACY_CROSS);                                    // walang key: bilang pa rin ngayong araw
        $row('WB-2', 'skipped', Chatblast::LEGACY_CAMPAIGN_CAP . ' (5 ngayong araw); ipadala ulit bukas');

        $this->assertSame(0, app(Chatblast::class)->build('in_transit', 1)['added'], 'walang bagong row ngayong araw para sa dalawa');
        $this->assertTrue(Chatblast::isTextReason(Chatblast::LEGACY_RIDER_REASON));
        $this->assertTrue(Chatblast::isTextReason('walang {cod}'));
        $this->assertTrue(Chatblast::isTextReason('hindi buo ang text: walang laman'));
        $this->assertTrue(Chatblast::isTextReason('missing {cod}'));
        $this->assertTrue(Chatblast::isTextReason('incomplete text: empty'));
        $this->assertTrue(Chatblast::isTextReason(Chatblast::RIDER_REASON));
        $this->assertFalse(Chatblast::isTextReason('the order has no PSID (not matched to a Botcake customer yet)'));
        $this->assertSame(2, BotcakeMessage::where('kind', 'in_transit')->count());
    }
}

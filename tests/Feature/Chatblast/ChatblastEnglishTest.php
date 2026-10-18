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
            ->assertSee('No messages in this filter')
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
        $this->assertFalse(Chatblast::isTextReason('the order has no PSID (not matched to a Botcake customer yet)'));
        $this->assertSame(2, BotcakeMessage::where('kind', 'in_transit')->count());
    }
}

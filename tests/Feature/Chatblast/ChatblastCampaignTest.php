<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeMessage;
use App\Models\ChatblastCampaign;
use App\Services\ChatblastSender;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * /chatblast/sequence: ang mga sequence (gawa, baguhin, alisin), ang auto mode na kailangang kumpirmahin at nakatala,
 * ang bilang ngayong araw, at ang access. Ang mga text ay nasa mga mensahe (ChatblastMessageTest).
 */
class ChatblastCampaignTest extends ChatblastTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->user());
        $this->travelTo('2026-10-18 10:00:00');
        $this->page('Page Uno');
        DB::table('from_jnts')->insert([['waybill_number' => 'WB-X1', 'status' => 'Problematic'], ['waybill_number' => 'WB-X2', 'status' => 'For Return']]);
    }

    private function valid(array $over = []): array
    {
        return $over + [
            'name' => 'Problema', 'statuses' => ['Problematic', 'For Return'], 'pages' => ['Page Uno'], 'window_days' => 10,
            'send_times' => ['09:00', '15:00'], 'requires_on_delivery' => false, 'text' => 'Hello {name}, may problema po ang {waybill}.',
        ];
    }

    private function create(array $over = []): ChatblastCampaign
    {
        $id = $this->postJson('/chatblast/sequence', $this->valid($over))->assertCreated()->json('campaign.id');

        return ChatblastCampaign::findOrFail($id);
    }

    private function msg(ChatblastCampaign $c, array $extra = []): int
    {
        return DB::table('botcake_messages')->insertGetId($extra + [
            'page_name' => 'Page Uno', 'waybill' => 'WB-' . uniqid(), 'kind' => $c->kind, 'campaign_id' => $c->id, 'day' => 1, 'version' => 'A',
            'psid' => 'psid-1', 'vars' => json_encode(['name' => 'Ana', 'waybill' => 'WB-1']), 'text' => 'luma', 'status' => 'ready',
            'attempts' => 0, 'is_test' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_a_new_campaign_is_manual_and_on_and_bad_input_saves_nothing(): void
    {
        $c = $this->create();
        $this->assertSame(['c' . $c->id, 'Problema', ['Problematic', 'For Return'], ['Page Uno'], 10, ['09:00', '15:00'], false, 'manual', true, [['Hello {name}, may problema po ang {waybill}.']]],
            [$c->kind, $c->name, $c->statuses, $c->pages, $c->window_days, $c->send_times, $c->requires_on_delivery, $c->mode, $c->active, $c->days]);
        $this->assertSame('Problema · Day 1', \App\Models\ChatblastMessage::findOrFail($c->step_messages[0])->name, 'ang text ay naging mensahe');
        $this->assertNull($this->create(['name' => 'Lahat ng page', 'pages' => []])->pages, 'walang page = lahat');
        $reuse = $this->postJson('/chatblast/sequence', ['message_id' => $c->step_messages[0], 'text' => null] + $this->valid(['name' => 'Gamit ang dati']))->assertCreated();
        $this->assertSame([$c->step_messages[0]], ChatblastCampaign::findOrFail($reuse->json('campaign.id'))->step_messages, 'o isang mensaheng mayroon na');
        $this->postJson('/chatblast/sequence', ['message_id' => 999999] + $this->valid(['name' => 'Wala']))->assertStatus(422);

        $bad = [
            'walang pangalan'          => ['name' => '  '],
            'walang status'            => ['statuses' => []],
            'status na wala sa J&T'    => ['statuses' => ['Nawawala']],
            'maling oras'              => ['send_times' => ['25:00']],
            'window na 0'              => ['window_days' => 0],
            'blangkong text'           => ['text' => '   '],
            'hindi kilalang placeholder' => ['text' => 'Hi {pangalan}'],
            'On Delivery sa ibang status' => ['requires_on_delivery' => true],
            'auto agad'                => ['mode' => 'auto'],
        ];
        $before = ChatblastCampaign::count();
        foreach ($bad as $why => $over) {
            $res = $this->postJson('/chatblast/sequence', $this->valid($over));
            if ($why === 'auto agad') {
                $res->assertCreated(); // ang mode ay hindi tinatanggap sa paggawa: laging manual
                $this->assertSame('manual', ChatblastCampaign::findOrFail($res->json('campaign.id'))->mode);
                continue;
            }
            $res->assertStatus(422);
            $this->assertIsString($res->json('error'), $why);
        }
        $this->assertSame($before + 1, ChatblastCampaign::count(), 'ang mali ay walang nagawang campaign');
    }

    public function test_switching_to_auto_needs_confirmation_and_is_logged_and_switching_off_stops_the_queued_rows(): void
    {
        $c = $this->create();
        $this->postJson('/chatblast/sequence/' . $c->id, ['mode' => 'auto'])->assertStatus(422);
        $this->postJson('/chatblast/sequence/' . $c->id, ['mode' => 'turbo'])->assertStatus(422);
        $this->postJson('/chatblast/sequence/' . $c->id, ['active' => 'siguro'])->assertStatus(422);
        $this->assertSame('manual', $c->fresh()->mode);

        $this->postJson('/chatblast/sequence/' . $c->id, ['mode' => 'auto', 'confirm' => true, 'name' => 'Problema 2'])->assertOk();
        $c->refresh();
        $this->assertSame(['auto', 'Problema 2', auth()->id()], [$c->mode, $c->name, $c->mode_changed_by]);
        $this->assertTrue($c->mode_changed_at->eq(now()));
        $this->assertEquals(['what' => 'mode', 'old' => 'manual', 'new' => 'auto', 'by' => auth()->id()], array_intersect_key(collect($c->log)->last(), array_flip(['what', 'old', 'new', 'by'])));

        $queued = $this->msg($c, ['status' => 'queued', 'dedupe_key' => 'WB-Q|' . $c->kind . '|1']);
        $sent   = $this->msg($c, ['status' => 'sent', 'sent_at' => now()]);
        $this->postJson('/chatblast/sequence/' . $c->id, ['active' => false])->assertOk();
        $this->assertFalse($c->fresh()->active);
        $this->assertSame(['skipped', ChatblastSender::OFF], [BotcakeMessage::find($queued)->status, BotcakeMessage::find($queued)->reason]);
        $this->assertSame('sent', BotcakeMessage::find($sent)->status);
        $this->assertSame(0, app(ChatblastSender::class)->queue([$queued], 1), 'hindi mai-queue ang row ng naka-off na campaign');
        $this->postJson('/chatblast/build', ['kind' => $c->kind])->assertStatus(422);
        $this->assertNull(BotcakeMessage::find($queued)->dedupe_key, 'binitawan ang key: ang pag-on ay gagawa ng bagong row');
        $this->travel(2)->hours();
        $this->postJson('/chatblast/sequence/' . $c->id, ['active' => true])->assertOk();
        $this->assertTrue($c->fresh()->auto_ran_at->eq(now()), 'ang lumipas na oras habang naka-off ay hindi hinahabol');
        $this->postJson('/chatblast/sequence/' . $c->id, ['active' => false])->assertOk();

        // Ang sariling status ng campaign na wala na sa from_jnts ay puwede pa ring i-save.
        ChatblastCampaign::whereKey($c->id)->update(['statuses' => json_encode(['Lumang status'])]);
        $this->postJson('/chatblast/sequence/' . $c->id, ['statuses' => ['Lumang status', 'Problematic']])->assertOk();
    }

    public function test_new_send_times_on_an_auto_campaign_start_from_now_and_overlapping_campaigns_are_warned(): void
    {
        $c = $this->create();
        $this->postJson('/chatblast/sequence/' . $c->id, ['mode' => 'auto', 'confirm' => true])->assertOk();
        $this->travel(3)->hours();
        $this->postJson('/chatblast/sequence/' . $c->id, ['send_times' => ['08:00', '12:00']])->assertOk();
        $this->assertTrue($c->fresh()->auto_ran_at->eq(now()->setTime(12, 0)), 'ang bagong oras na lumipas na ay hindi tatakbo');
        $this->postJson('/chatblast/sequence/' . $c->id, ['send_times' => ['08:00', '12:00', '18:00']])->assertOk();
        $this->assertTrue($c->fresh()->auto_ran_at->eq(now()->setTime(12, 0)), 'ang oras na darating pa ay walang epekto');

        DB::table('from_jnts')->insert(['waybill_number' => 'WB-X3', 'status' => 'In Transit']);
        $this->assertSame([], $this->getJson('/chatblast/sequence/data')->json('overlaps'));
        $this->create(['name' => 'Biyahe rin', 'statuses' => ['In Transit'], 'pages' => ['Page Uno']]);
        $this->create(['name' => 'Ibang page', 'statuses' => ['In Transit'], 'pages' => ['Page Dos']]);
        $this->assertSame(['"In transit" at "Biyahe rin": In Transit sa Page Uno', '"In transit" at "Ibang page": In Transit sa Page Dos'],
            $this->getJson('/chatblast/sequence/data')->json('overlaps'));
    }

    public function test_a_campaign_with_messages_cannot_be_deleted(): void
    {
        $used = $this->create();
        $this->msg($used);
        $this->deleteJson('/chatblast/sequence/' . $used->id)->assertStatus(422);
        $empty = $this->create(['name' => 'Bago']);
        $this->deleteJson('/chatblast/sequence/' . $empty->id)->assertOk();
        $this->assertSame([true, false], [ChatblastCampaign::whereKey($used->id)->exists(), ChatblastCampaign::whereKey($empty->id)->exists()]);
    }

    public function test_the_data_shows_todays_counts_per_campaign_and_one_sheet_row_per_day_and_version_with_its_sends(): void
    {
        $c = $this->create();
        $this->msg($c);
        $this->msg($c, ['status' => 'sent', 'sent_at' => now(), 'version' => 'B']);
        $this->msg($c, ['status' => 'sent', 'sent_at' => now()->subDays(3), 'created_at' => now()->subDays(3), 'version' => 'B']);
        $this->msg($c, ['status' => 'failed']);
        $this->msg($c, ['status' => 'sent', 'sent_at' => now(), 'is_test' => true]); // ang test ay hindi bilang

        $d = $this->getJson('/chatblast/sequence/data')->assertOk()->json();
        $mine = collect($d['campaigns'])->firstWhere('id', $c->id);
        $this->assertSame(['built' => 3, 'sent' => 1, 'failed' => 1, 'skipped' => 0], $mine['today']);
        $this->assertSame(1, $mine['ready'], 'ilang ready ngayon (para sa kumpirmasyon ng auto)');
        $this->assertSame([[1, 'Problema · Day 1', ['built' => 3, 'sent' => 1, 'failed' => 1, 'skipped' => 0]]],
            array_map(fn ($s) => [$s['day'], $s['message'], $s['today']], $mine['steps']), 'ang bilang ng step ngayong araw');
        $this->assertContains('Problematic', $d['statuses']);
        $this->assertContains('In transit', array_column($d['campaigns'], 'name'), 'ang mga dating uri ay campaign din');

        $this->get('/chatblast/sequence')->assertOk()->assertSee('Mga sequence')->assertSee(route('chatblast.campaigns.index'), false);
        $this->get('/chatblast/campaigns')->assertOk()->assertSee('Mga mensahe')->assertSee('Flow at template')->assertSee(route('chatblast.sequence.index'), false);
    }

    public function test_only_the_ceo_and_a_checked_role_reach_the_campaigns(): void
    {
        $c = $this->create();
        Artisan::call('migrate', ['--path' => 'database/migrations/2026_05_18_140000_create_nav_links_and_visibility_tables.php', '--force' => true]);
        Artisan::call('migrate', ['--path' => 'database/migrations/2026_10_18_300100_add_chatblast_nav_link.php', '--force' => true]);
        $link = DB::table('nav_links')->where('key', 'chatblast')->first();
        DB::table('nav_link_role_visibility')->updateOrInsert(['nav_link_id' => $link->id, 'role' => 'Marketing - OIC'], ['is_visible' => true]);

        $this->actingAs($this->user('Marketing - OIC', 'oic@example.test'));
        $this->getJson('/chatblast/sequence/data')->assertOk();

        $this->actingAs($this->user('Staff', 'staff@example.test'));
        $this->get('/chatblast/sequence')->assertNotFound();
        $this->getJson('/chatblast/sequence/data')->assertNotFound();
        $this->postJson('/chatblast/sequence', $this->valid())->assertNotFound();
        foreach (['', '/step', '/remove-step', '/move', '/step-message', '/step-times'] as $action) {
            $this->postJson('/chatblast/sequence/' . $c->id . $action, ['day' => 1, 'after' => 1, 'dir' => 'down', 'times' => ['10:00'], 'message_id' => $c->step_messages[0], 'active' => false])->assertNotFound();
        }
        $this->deleteJson('/chatblast/sequence/' . $c->id)->assertNotFound();
        $this->assertSame([['Hello {name}, may problema po ang {waybill}.']], $c->fresh()->days);
        $this->assertTrue($c->fresh()->active);
    }
}

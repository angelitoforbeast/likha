<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeMessage;
use App\Models\ChatblastCampaign;
use App\Services\ChatblastSender;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * /chatblast/campaigns: ang mga campaign (gawa, baguhin, alisin), ang isang sheet ng lahat ng text (isang cell kada
 * araw at bersyon), ang auto mode na kailangang kumpirmahin at nakatala, ang bilang ngayong araw, at ang access.
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
        $id = $this->postJson('/chatblast/campaigns', $this->valid($over))->assertCreated()->json('campaign.id');

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
        $this->assertNull($this->create(['name' => 'Lahat ng page', 'pages' => []])->pages, 'walang page = lahat');

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
            $res = $this->postJson('/chatblast/campaigns', $this->valid($over));
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
        $this->postJson('/chatblast/campaigns/' . $c->id, ['mode' => 'auto'])->assertStatus(422);
        $this->postJson('/chatblast/campaigns/' . $c->id, ['mode' => 'turbo'])->assertStatus(422);
        $this->postJson('/chatblast/campaigns/' . $c->id, ['active' => 'siguro'])->assertStatus(422);
        $this->assertSame('manual', $c->fresh()->mode);

        $this->postJson('/chatblast/campaigns/' . $c->id, ['mode' => 'auto', 'confirm' => true, 'name' => 'Problema 2'])->assertOk();
        $c->refresh();
        $this->assertSame(['auto', 'Problema 2', auth()->id()], [$c->mode, $c->name, $c->mode_changed_by]);
        $this->assertTrue($c->mode_changed_at->eq(now()));
        $this->assertEquals(['what' => 'mode', 'old' => 'manual', 'new' => 'auto', 'by' => auth()->id()], array_intersect_key(collect($c->log)->last(), array_flip(['what', 'old', 'new', 'by'])));

        $queued = $this->msg($c, ['status' => 'queued', 'dedupe_key' => 'WB-Q|' . $c->kind . '|1']);
        $sent   = $this->msg($c, ['status' => 'sent', 'sent_at' => now()]);
        $this->postJson('/chatblast/campaigns/' . $c->id, ['active' => false])->assertOk();
        $this->assertFalse($c->fresh()->active);
        $this->assertSame(['skipped', ChatblastSender::OFF], [BotcakeMessage::find($queued)->status, BotcakeMessage::find($queued)->reason]);
        $this->assertSame('sent', BotcakeMessage::find($sent)->status);
        $this->assertSame(0, app(ChatblastSender::class)->queue([$queued], 1), 'hindi mai-queue ang row ng naka-off na campaign');
        $this->postJson('/chatblast/build', ['kind' => $c->kind])->assertStatus(422);
        $this->assertNull(BotcakeMessage::find($queued)->dedupe_key, 'binitawan ang key: ang pag-on ay gagawa ng bagong row');
        $this->travel(2)->hours();
        $this->postJson('/chatblast/campaigns/' . $c->id, ['active' => true])->assertOk();
        $this->assertTrue($c->fresh()->auto_ran_at->eq(now()), 'ang lumipas na oras habang naka-off ay hindi hinahabol');
        $this->postJson('/chatblast/campaigns/' . $c->id, ['active' => false])->assertOk();

        // Ang sariling status ng campaign na wala na sa from_jnts ay puwede pa ring i-save.
        ChatblastCampaign::whereKey($c->id)->update(['statuses' => json_encode(['Lumang status'])]);
        $this->postJson('/chatblast/campaigns/' . $c->id, ['statuses' => ['Lumang status', 'Problematic']])->assertOk();
    }

    public function test_one_cell_saves_its_text_logs_the_old_one_and_rewrites_the_unsent_rows(): void
    {
        $c = $this->create();
        $this->postJson('/chatblast/campaigns/' . $c->id . '/version', ['day' => 1])->assertOk();
        $this->assertSame([['Hello {name}, may problema po ang {waybill}.', 'Hello {name}, may problema po ang {waybill}.']], $c->fresh()->days, 'kopya ng A');

        $unsent = $this->msg($c, ['version' => 'B', 'waybill' => 'WB-1']);
        $sent   = $this->msg($c, ['status' => 'sent', 'sent_at' => now()]);
        $res = $this->postJson('/chatblast/campaigns/' . $c->id . '/text', ['day' => 1, 'version' => 'B', 'text' => ' Hi {name}, {waybill} po. ', 'old' => 'Hello {name}, may problema po ang {waybill}.'])->assertOk();
        $this->assertSame(1, $res->json('changed'));
        $this->assertSame('Hi {name}, {waybill} po.', $c->fresh()->days[0][1]);
        $this->assertSame(['what' => 'text', 'day' => 1, 'version' => 'B', 'old' => 'Hello {name}, may problema po ang {waybill}.', 'new' => 'Hi {name}, {waybill} po.'],
            array_intersect_key(collect($c->fresh()->log)->last(), array_flip(['what', 'day', 'version', 'old', 'new'])));
        $this->assertSame('Hi Ana, WB-1 po.', BotcakeMessage::find($unsent)->text);
        $this->assertSame('luma', BotcakeMessage::find($sent)->text, 'ang naipadala ay hindi binabago');

        foreach ([['day' => 1, 'version' => 'B', 'text' => ''], ['day' => 1, 'version' => 'B', 'text' => '{foo}'], ['day' => 2, 'version' => 'A', 'text' => 'x'], ['day' => 1, 'version' => 'C', 'text' => 'x'], ['day' => 1, 'version' => 'B', 'text' => 'x', 'old' => 'ibang nakita']] as $body) {
            $this->postJson('/chatblast/campaigns/' . $c->id . '/text', $body)->assertStatus(422);
        }
        $this->assertSame('Hi {name}, {waybill} po.', $c->fresh()->days[0][1], 'walang nabago sa mali');
    }

    public function test_days_and_versions_are_added_and_removed_but_the_last_day_stays(): void
    {
        $c = $this->create(['text' => 'Araw isa']);
        $this->postJson('/chatblast/campaigns/' . $c->id . '/day')->assertOk();
        $this->postJson('/chatblast/campaigns/' . $c->id . '/text', ['day' => 2, 'version' => 'A', 'text' => 'Araw dalawa'])->assertOk();
        $this->postJson('/chatblast/campaigns/' . $c->id . '/version', ['day' => 2])->assertOk();
        $this->assertSame([['Araw isa'], ['Araw dalawa', 'Araw dalawa']], $c->fresh()->days);

        $this->postJson('/chatblast/campaigns/' . $c->id . '/remove', ['day' => 2, 'version' => 'A', 'old' => 'iba na'])->assertStatus(422);
        $this->postJson('/chatblast/campaigns/' . $c->id . '/remove', ['day' => 2, 'version' => 'A'])->assertOk();
        $this->assertSame([['Araw isa'], ['Araw dalawa']], $c->fresh()->days);
        $this->postJson('/chatblast/campaigns/' . $c->id . '/remove', ['day' => 1, 'version' => 'A'])->assertOk();
        $this->assertSame([['Araw dalawa']], $c->fresh()->days, 'ang huling bersyon ay inaalis ang araw');
        $this->postJson('/chatblast/campaigns/' . $c->id . '/remove', ['day' => 1, 'version' => 'A'])->assertStatus(422);
        $this->assertSame([['Araw dalawa']], $c->fresh()->days, 'kahit isang araw');
    }

    public function test_a_campaign_with_messages_cannot_be_deleted(): void
    {
        $used = $this->create();
        $this->msg($used);
        $this->deleteJson('/chatblast/campaigns/' . $used->id)->assertStatus(422);
        $empty = $this->create(['name' => 'Bago']);
        $this->deleteJson('/chatblast/campaigns/' . $empty->id)->assertOk();
        $this->assertSame([true, false], [ChatblastCampaign::whereKey($used->id)->exists(), ChatblastCampaign::whereKey($empty->id)->exists()]);
    }

    public function test_the_data_shows_todays_counts_per_campaign_and_one_sheet_row_per_day_and_version_with_its_sends(): void
    {
        $c = $this->create();
        $this->postJson('/chatblast/campaigns/' . $c->id . '/version', ['day' => 1])->assertOk();
        $this->msg($c);
        $this->msg($c, ['status' => 'sent', 'sent_at' => now(), 'version' => 'B']);
        $this->msg($c, ['status' => 'sent', 'sent_at' => now()->subDays(3), 'created_at' => now()->subDays(3), 'version' => 'B']);
        $this->msg($c, ['status' => 'failed']);
        $this->msg($c, ['status' => 'sent', 'sent_at' => now(), 'is_test' => true]); // ang test ay hindi bilang

        $d = $this->getJson('/chatblast/campaigns/data')->assertOk()->json();
        $mine = collect($d['campaigns'])->firstWhere('id', $c->id);
        $this->assertSame(['built' => 3, 'sent' => 1, 'failed' => 1], $mine['today']);
        $rows = collect($d['sheet'])->where('campaign_id', $c->id)->values()->all();
        $this->assertSame([[1, 'A', 0], [1, 'B', 2]], array_map(fn ($r) => [$r['day'], $r['version'], $r['sent']], $rows));
        $this->assertContains('Problematic', $d['statuses']);
        $this->assertContains('In transit', array_column($d['campaigns'], 'name'), 'ang mga dating uri ay campaign din');

        $this->get('/chatblast/campaigns')->assertOk()->assertSee('Campaigns')->assertSee('Flow at template')->assertSee(route('chatblast.index'), false);
    }

    public function test_only_the_ceo_and_a_checked_role_reach_the_campaigns(): void
    {
        $c = $this->create();
        Artisan::call('migrate', ['--path' => 'database/migrations/2026_05_18_140000_create_nav_links_and_visibility_tables.php', '--force' => true]);
        Artisan::call('migrate', ['--path' => 'database/migrations/2026_10_18_300100_add_chatblast_nav_link.php', '--force' => true]);
        $link = DB::table('nav_links')->where('key', 'chatblast')->first();
        DB::table('nav_link_role_visibility')->updateOrInsert(['nav_link_id' => $link->id, 'role' => 'Marketing - OIC'], ['is_visible' => true]);

        $this->actingAs($this->user('Marketing - OIC', 'oic@example.test'));
        $this->getJson('/chatblast/campaigns/data')->assertOk();

        $this->actingAs($this->user('Staff', 'staff@example.test'));
        $this->get('/chatblast/campaigns')->assertNotFound();
        $this->getJson('/chatblast/campaigns/data')->assertNotFound();
        $this->postJson('/chatblast/campaigns', $this->valid())->assertNotFound();
        foreach (['', '/text', '/day', '/version', '/remove'] as $action) {
            $this->postJson('/chatblast/campaigns/' . $c->id . $action, ['day' => 1, 'version' => 'A', 'text' => 'x', 'active' => false])->assertNotFound();
        }
        $this->deleteJson('/chatblast/campaigns/' . $c->id)->assertNotFound();
        $this->assertSame([['Hello {name}, may problema po ang {waybill}.']], $c->fresh()->days);
        $this->assertTrue($c->fresh()->active);
    }
}

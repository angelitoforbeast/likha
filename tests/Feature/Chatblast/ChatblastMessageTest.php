<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeMessage;
use App\Models\ChatblastCampaign;
use App\Models\ChatblastMessage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * /chatblast/campaigns, "Mga mensahe": ang text ng bawat bersyon ay sine-save sa lugar (may log at old), ang bawat
 * sequence na gumagamit ng mensahe ay sumusunod (days at ang hindi pa naipapadala), gawa / palit-pangalan / kopya /
 * alis (hindi kapag ginagamit), kung saan ginagamit at ilan ang naipadala, at ang access.
 */
class ChatblastMessageTest extends ChatblastTestCase
{
    private ChatblastCampaign $c;
    private ChatblastMessage $m;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->user());
        $this->travelTo('2026-10-18 10:00:00');
        $this->page('Page Uno');
        $this->c = ChatblastCampaign::where('kind', 'in_transit')->sole();
        $this->m = ChatblastMessage::findOrFail($this->c->step_messages[0]); // "In transit · Day 1"
    }

    private function url(string $tail = ''): string
    {
        return '/chatblast/messages/' . $this->m->id . $tail;
    }

    private function row(array $extra = []): int
    {
        return DB::table('botcake_messages')->insertGetId($extra + [
            'page_name' => 'Page Uno', 'waybill' => 'WB-' . uniqid(), 'kind' => 'in_transit', 'campaign_id' => $this->c->id, 'day' => 1, 'version' => 'A',
            'psid' => 'psid-1', 'vars' => json_encode(['name' => 'Ana', 'waybill' => 'WB-1']), 'text' => 'luma', 'status' => 'ready',
            'attempts' => 0, 'is_test' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_one_version_saves_in_place_logs_the_old_text_and_every_sequence_using_it_follows(): void
    {
        $this->postJson($this->url('/version'))->assertOk();
        $a = $this->m->fresh()->versions[0];
        $this->assertSame([$a, $a], $this->m->fresh()->versions, 'kopya ng A');
        // Isa pang sequence na gumagamit ng parehong mensahe sa Day 2.
        $other = ChatblastCampaign::where('kind', 'delivering')->sole();
        $other->update(['step_messages' => [$other->step_messages[0], $this->m->id], 'days' => [$other->days[0], $this->m->fresh()->versions]]);

        $unsent = $this->row(['version' => 'B', 'waybill' => 'WB-1']);
        $sent = $this->row(['status' => 'sent', 'sent_at' => now()]);
        $res = $this->postJson($this->url('/text'), ['version' => 'B', 'text' => ' Hi {name}, {waybill} po. ', 'old' => $a])->assertOk();
        $this->assertSame(1, $res->json('changed'));
        $this->assertSame([$a, 'Hi {name}, {waybill} po.'], $this->m->fresh()->versions);
        $this->assertSame(['what' => 'text', 'version' => 'B', 'old' => $a, 'new' => 'Hi {name}, {waybill} po.'],
            array_intersect_key(collect($this->m->fresh()->log)->last(), array_flip(['what', 'version', 'old', 'new'])));
        $this->assertSame([$a, 'Hi {name}, {waybill} po.'], $this->c->fresh()->days[0], 'sumunod ang In transit');
        $this->assertSame([$a, 'Hi {name}, {waybill} po.'], $other->fresh()->days[1], 'sumunod ang Delivering Day 2');
        $this->assertSame('Hi Ana, WB-1 po.', BotcakeMessage::find($unsent)->text);
        $this->assertSame('luma', BotcakeMessage::find($sent)->text, 'ang naipadala ay hindi binabago');

        foreach ([['version' => 'B', 'text' => ''], ['version' => 'B', 'text' => '{foo}'], ['version' => 'C', 'text' => 'x'], ['version' => 'B', 'text' => 'x', 'old' => 'ibang nakita'], ['version' => 'B', 'text' => str_repeat('x', 1001)]] as $body) {
            $this->postJson($this->url('/text'), $body)->assertStatus(422);
        }
        $this->assertSame('Hi {name}, {waybill} po.', $this->m->fresh()->versions[1], 'walang nabago sa mali');
    }

    public function test_versions_are_removed_but_one_stays_and_a_used_message_cannot_be_deleted(): void
    {
        $this->postJson($this->url('/version'))->assertOk();
        $b = $this->m->fresh()->versions[1];
        $this->postJson($this->url('/remove-version'), ['version' => 'B', 'old' => 'iba na'])->assertStatus(422);
        $this->postJson($this->url('/remove-version'), ['version' => 'B', 'old' => $b])->assertOk();
        $this->assertCount(1, $this->m->fresh()->versions);
        $this->postJson($this->url('/remove-version'), ['version' => 'A'])->assertStatus(422);
        $this->assertCount(1, $this->m->fresh()->versions, 'kahit isang bersyon');

        $this->deleteJson($this->url())->assertStatus(422);
        $this->assertTrue(ChatblastMessage::whereKey($this->m->id)->exists());
    }

    public function test_messages_are_created_renamed_duplicated_and_deleted_when_unused(): void
    {
        $id = $this->postJson('/chatblast/messages', ['name' => ' In transit · Day 3 · bayad ', 'text' => 'Bayad {cod}'])->assertCreated()->json('message.id');
        $new = ChatblastMessage::findOrFail($id);
        $this->assertSame(['In transit · Day 3 · bayad', ['Bayad {cod}']], [$new->name, $new->versions]);
        foreach ([['name' => '', 'text' => 'x'], ['name' => 'In transit · Day 3 · bayad', 'text' => 'x'], ['name' => 'Iba', 'text' => '  '], ['name' => 'Iba', 'text' => 'Hi {pangalan}']] as $body) {
            $this->postJson('/chatblast/messages', $body)->assertStatus(422);
        }

        $this->postJson('/chatblast/messages/' . $id, ['name' => 'Bayad paalala'])->assertOk();
        $this->postJson('/chatblast/messages/' . $id, ['name' => $this->m->name])->assertStatus(422);
        $copy = $this->postJson('/chatblast/messages/' . $id . '/duplicate')->assertCreated()->json('message');
        $this->assertSame(['Bayad paalala (kopya)', ['Bayad {cod}']], [$copy['name'], $copy['versions']]);

        $this->deleteJson('/chatblast/messages/' . $copy['id'])->assertOk();
        $this->assertFalse(ChatblastMessage::whereKey($copy['id'])->exists());
        $this->assertSame(['create', 'rename'], collect(ChatblastMessage::findOrFail($id)->log)->pluck('what')->all());
    }

    public function test_the_data_shows_where_each_message_is_used_and_its_sends_per_version(): void
    {
        $this->postJson($this->url('/version'))->assertOk();
        $this->row(['status' => 'sent', 'sent_at' => now()]);
        $this->row(['status' => 'sent', 'sent_at' => now(), 'version' => 'B']);
        $this->row(['status' => 'sent', 'sent_at' => now(), 'version' => 'B']);
        $this->row(['status' => 'sent', 'sent_at' => now(), 'is_test' => true]);
        $this->row(['status' => 'sent', 'sent_at' => now(), 'day' => 2]); // ibang step: ibang mensahe
        $this->row(['status' => 'sent', 'sent_at' => now()->subDays(31), 'created_at' => now()->subDays(31)]); // lampas sa 30 araw: hindi bilang

        $d = $this->getJson('/chatblast/campaigns/data')->assertOk()->json();
        $mine = collect($d['messages'])->firstWhere('id', $this->m->id);
        $this->assertSame([['campaign_id' => $this->c->id, 'name' => 'In transit', 'day' => 1]], $mine['used']);
        $this->assertSame([['A' => 1, 'B' => 2], 3, 30], [$mine['sent'], $mine['sent_total'], $d['sent_days']]);
        $this->assertSame(['In transit', 'Delivering'], array_column($d['campaigns'], 'name'));
        $this->get('/chatblast/campaigns')->assertOk()->assertSee('Messages');
    }

    public function test_another_role_cannot_see_or_change_the_messages(): void
    {
        Artisan::call('migrate', ['--path' => 'database/migrations/2026_05_18_140000_create_nav_links_and_visibility_tables.php', '--force' => true]);
        Artisan::call('migrate', ['--path' => 'database/migrations/2026_10_18_300100_add_chatblast_nav_link.php', '--force' => true]);
        $this->actingAs($this->user('Staff', 'staff@example.test'));
        $this->get('/chatblast/campaigns')->assertNotFound();
        $this->getJson('/chatblast/campaigns/data')->assertNotFound();
        $this->postJson('/chatblast/messages', ['name' => 'X', 'text' => 'x'])->assertNotFound();
        foreach (['', '/duplicate', '/text', '/version', '/remove-version'] as $path) {
            $this->postJson($this->url($path), ['name' => 'X', 'version' => 'A', 'text' => 'x'])->assertNotFound();
        }
        $this->deleteJson($this->url())->assertNotFound();
        $this->assertSame([$this->c->days[0], 'In transit · Day 1'], [$this->m->fresh()->versions, $this->m->fresh()->name]);
    }
}

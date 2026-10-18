<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeMessage;
use App\Models\BotcakePsid;
use App\Services\Chatblast;
use App\Services\PageSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/** Ang /chatblast: listahan na parang sheet, bilang kada status, mga filter, side panel, mga button at access. */
class ChatblastPageTest extends ChatblastTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->user());
        $this->travelTo('2026-10-18 10:00:00');
    }

    /** Isang row nang direkta (walang build), para sa listahan at bilang. */
    private function msg(string $status, array $extra = []): int
    {
        return DB::table('botcake_messages')->insertGetId($extra + [
            'page_name' => 'Page Uno', 'waybill' => 'WB-' . uniqid(), 'kind' => 'delivering', 'fb_name' => 'Buyer', 'psid' => 'psid-123456',
            'text' => "Hello Buyer\nIkalawang linya", 'status' => $status, 'attempts' => 0, 'is_test' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function list(array $query = [])
    {
        return $this->getJson('/chatblast/list?' . http_build_query($query))->assertOk();
    }

    public function test_the_list_counts_each_status_for_the_filter_and_pages_100_rows_newest_first(): void
    {
        for ($i = 0; $i < 103; $i++) $this->msg('ready');
        $this->msg('sent', ['sent_at' => now()]);
        $this->msg('failed', ['last_http' => 500]);
        $this->msg('no_psid', ['psid' => null, 'reason' => 'walang PSID']);
        $this->msg('ready', ['page_name' => 'Page Dos']);
        $this->msg('ready', ['created_at' => now()->subDays(2)]); // hindi ngayong araw, pero ready pa: kasama sa default
        $this->msg('sent', ['created_at' => now()->subDays(2), 'sent_at' => now()->subDays(2)]); // hindi ngayong araw

        $res = $this->list();
        $this->assertSame(['failed' => 1, 'no_psid' => 1, 'ready' => 105, 'sent' => 1], $res->json('counts'), 'default: ngayong araw at ang ready pa');
        $this->assertSame([108, 100, 1], [$res->json('total'), count($res->json('rows')), $res->json('page')]);
        $this->assertSame('Hello Buyer', $res->json('rows.0.first_line'));
        $this->assertSame('3456', $res->json('rows.0.psid_last4'));
        $this->assertArrayNotHasKey('psid', $res->json('rows.0'));
        $this->assertCount(8, $this->list(['p' => 2])->json('rows'));

        $this->assertSame(['ready' => 1], $this->list(['page' => 'Page Dos'])->json('counts'));
        $this->assertSame(1, $this->list(['status' => 'failed'])->json('total'));
        $this->assertSame(['failed' => 1, 'no_psid' => 1, 'ready' => 105, 'sent' => 2], $this->list(['from' => now()->subDays(3)->toDateString()])->json('counts'), 'ibang petsa: eksakto');
        $this->assertSame(['paused' => false, 'queued' => 0, 'sent' => 1], array_intersect_key($this->list()->json('state'), array_flip(['sent', 'queued', 'paused'])));
    }

    public function test_search_finds_a_name_start_or_an_exact_waybill(): void
    {
        $this->msg('ready', ['fb_name' => 'Ana Cruz', 'waybill' => 'JT0001']);
        $this->msg('ready', ['fb_name' => 'Ben Reyes', 'waybill' => 'JT0002']);

        $this->assertSame(['JT0001'], array_column($this->list(['q' => 'ana'])->json('rows'), 'waybill'));
        $this->assertSame(['JT0002'], array_column($this->list(['q' => 'JT0002'])->json('rows'), 'waybill'));
        $this->assertSame([], $this->list(['q' => 'JT000'])->json('rows'), 'waybill: eksakto lang');
    }

    public function test_the_query_count_is_the_same_for_10_and_1000_rows(): void
    {
        $count = function (int $n): int {
            $rows = [];
            for ($i = 0; $i < $n; $i++) {
                $rows[] = ['page_name' => 'Page ' . ($i % 20), 'waybill' => 'WB-' . $n . '-' . $i, 'kind' => 'delivering', 'fb_name' => 'B' . $i,
                    'psid' => 'p' . $i, 'text' => 't', 'status' => ['ready', 'sent', 'failed', 'no_psid'][$i % 4], 'attempts' => 0, 'is_test' => false,
                    'created_at' => now(), 'updated_at' => now()];
            }
            foreach (array_chunk($rows, 200) as $chunk) DB::table('botcake_messages')->insert($chunk);
            DB::table('from_jnts')->insert(['waybill_number' => 'WB-' . $n . '-0', 'status' => 'Delivering']);

            $this->list(); // unang request: auth at profile
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->list();
            $q = count(DB::getQueryLog());
            DB::disableQueryLog();
            DB::table('botcake_messages')->delete();
            return $q;
        };

        $ten = $count(10);
        $this->assertSame($ten, $count(1000));
        $this->assertLessThanOrEqual(7, $ten);
    }

    public function test_the_side_panel_shows_every_step_and_never_the_token(): void
    {
        $this->page('Page Uno');
        $this->shipped('Page Uno', 'Ana Cruz', 'WB-001', 'psid-a');
        app(Chatblast::class)->build('delivering');
        $id = BotcakeMessage::sole()->id;
        $written = null;
        Http::fake(['botcake.io/*' => function (Request $req) use (&$written) {
            if (str_ends_with($req->url(), '/customer_fields') && $req->method() === 'POST') $written = $req->data()['data'][0]['value'];
            if ($req->method() === 'GET') return Http::response(['success' => true, 'data' => [['id' => 7701, 'value' => $written]]]);
            return Http::response(['success' => true]);
        }]);
        $this->postJson('/chatblast/send', ['ids' => [$id]])->assertOk()->assertJsonPath('queued', 1);

        $res = $this->getJson('/chatblast/messages/' . $id)->assertOk();

        $this->assertSame('sent', $res->json('status'));
        $this->assertSame(['write', 'read', 'flow'], array_column($res->json('steps'), 'step'));
        $this->assertSame('psid-a', $res->json('psid'), 'buo ang PSID sa side panel, para sa debug');
        $this->assertStringContainsString('Idedeliver po ngayon', $res->json('text'));
        $this->assertStringNotContainsString(self::TOKEN, $res->getContent());
        $this->assertStringNotContainsString('9w2e', $res->getContent());
        $this->assertStringNotContainsString(self::TOKEN, $this->get('/chatblast')->assertOk()->getContent());
    }

    public function test_the_buttons_build_send_retry_and_pause(): void
    {
        Queue::fake([\App\Jobs\SendChatblastMessage::class]); // ang build job ay tumatakbo (sync queue sa test)
        $this->page('Page Uno');
        $this->shipped('Page Uno', 'Ana Cruz', 'WB-001', 'psid-a');
        $this->shipped('Page Uno', 'Ben Reyes', 'WB-002', 'psid-b');
        $this->shipped('Page Uno', 'Cora Diaz', 'WB-003', null);

        $this->postJson('/chatblast/build', ['kind' => 'delivering'])->assertOk()->assertJsonPath('run.status', 'done')->assertJsonPath('run.result.added', 3);
        BotcakeMessage::where('waybill', 'WB-002')->update(['status' => 'failed']);
        DB::table('botcake_messages')->where('waybill', 'WB-002')->update(['created_at' => now()->subDays(5)]);

        $this->postJson('/chatblast/send-filtered', ['status' => 'ready'])->assertOk()->assertJsonPath('queued', 1); // WB-001 lang; ang no_psid ay hindi
        $this->postJson('/chatblast/retry-failed', ['from' => now()->subDays(7)->toDateString()])->assertOk()->assertJsonPath('queued', 1);
        $this->assertSame(['queued', 'queued', 'no_psid'], BotcakeMessage::orderBy('waybill')->pluck('status')->all());
        Queue::assertPushedOn('chatblast', \App\Jobs\SendChatblastMessage::class);

        $this->postJson('/chatblast/pause')->assertOk()->assertJsonPath('state.paused', true);
        $this->postJson('/chatblast/resume')->assertOk()->assertJsonPath('state.paused', false);

        $this->postJson('/chatblast/build', ['kind' => 'iba'])->assertStatus(422);
        $this->postJson('/chatblast/send', ['ids' => 'hindi-listahan'])->assertStatus(422);
    }

    public function test_the_test_psid_is_picked_from_the_page_customers_by_name_and_used_by_send_test(): void
    {
        Queue::fake();
        $this->page('Page Uno');
        $this->shipped('Page Uno', 'Ana Cruz', 'WB-001', 'psid-a');
        app(Chatblast::class)->build('delivering');
        $owner = BotcakePsid::create(['page' => 'Page Uno', 'fb_name' => 'Owner Account', 'psid' => 'psid-owner-7788', 'status' => 'available', 'source' => 'api']);
        BotcakePsid::create(['page' => 'Page Dos', 'fb_name' => 'Owner Account', 'psid' => 'psid-other-1111', 'status' => 'available', 'source' => 'api']);

        $this->postJson('/chatblast/test', ['id' => BotcakeMessage::sole()->id])->assertStatus(422); // wala pang test PSID

        $found = $this->getJson('/chatblast/test-psid?' . http_build_query(['page' => 'Page Uno', 'q' => 'owner']))->assertOk()->json('customers');
        $this->assertSame([['id' => $owner->id, 'name' => 'Owner Account', 'psid_last4' => '7788']], $found);

        $this->postJson('/chatblast/test-psid', ['page' => 'Page Uno', 'customer_id' => $owner->id])->assertOk();
        $this->assertSame('psid-owner-7788', app(PageSettings::class)->get('Page Uno', 'botcake', 'test_psid'));
        $other = BotcakePsid::where('psid', 'psid-other-1111')->value('id');
        $this->postJson('/chatblast/test-psid', ['page' => 'Page Uno', 'customer_id' => $other])->assertStatus(422); // ibang page

        $this->postJson('/chatblast/test', ['id' => BotcakeMessage::where('is_test', false)->sole()->id])->assertOk();
        $this->assertSame(['psid-owner-7788', 'queued'], [BotcakeMessage::where('is_test', true)->value('psid'), BotcakeMessage::where('is_test', true)->value('status')]);
        $this->assertSame('ready', BotcakeMessage::where('is_test', false)->value('status'));

        $grid = $this->get('/chatblast/page-settings')->assertOk()->viewData('grid');
        $this->assertContains('botcake.test_psid', array_column($grid['columns'], 'key'));
        $this->assertSame('Test PSID', collect($grid['columns'])->firstWhere('key', 'botcake.test_psid')['label']);
    }

    public function test_the_ceo_and_a_checked_role_reach_chatblast_and_others_get_404(): void
    {
        Queue::fake();
        Artisan::call('migrate', ['--path' => 'database/migrations/2026_05_18_140000_create_nav_links_and_visibility_tables.php', '--force' => true]);
        Artisan::call('migrate', ['--path' => 'database/migrations/2026_10_18_300100_add_chatblast_nav_link.php', '--force' => true]);
        $link = DB::table('nav_links')->where('key', 'chatblast')->first();
        $this->assertSame('/chatblast', $link->route_url);
        $this->assertTrue((bool) DB::table('nav_link_role_visibility')->where('nav_link_id', $link->id)->where('role', 'CEO')->value('is_visible'));
        DB::table('nav_link_role_visibility')->updateOrInsert(['nav_link_id' => $link->id, 'role' => 'Marketing - OIC'], ['is_visible' => true]);
        $id = $this->msg('ready');

        $this->get('/chatblast')->assertOk();

        $this->actingAs($this->user('Marketing - OIC', 'oic@example.test'));
        $this->get('/chatblast')->assertOk();
        $this->list();

        $this->actingAs($this->user('Data Encoder', 'enc@example.test'));
        $this->get('/chatblast')->assertNotFound();
        $this->getJson('/chatblast/list')->assertNotFound();
        $this->getJson('/chatblast/messages/' . $id)->assertNotFound();
        foreach (['build', 'send', 'send-filtered', 'retry-failed', 'pause', 'resume', 'test', 'test-psid'] as $action) {
            $this->postJson('/chatblast/' . $action, ['ids' => [$id], 'id' => $id])->assertNotFound();
        }
        Queue::assertNothingPushed();
        $this->assertSame('ready', BotcakeMessage::find($id)->status);
    }
}

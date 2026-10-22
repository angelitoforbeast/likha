<?php

namespace Tests\Feature\Chatblast;

use App\Models\PageSetting;
use App\Services\PageSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Ang history ng /chatbot/pages: ang mga bersyon ng isang field (History, Restore), ang Changes log ng lahat ng page, at
 * ang Preview. Galing lahat sa page_settings (ang lumang row ay naiiwan na active = false).
 */
class ChatbotPageHistoryTest extends ChatblastTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Artisan::call('migrate', ['--path' => ['database/migrations/2026_10_20_100000_create_botcake_chats_table.php']]);
        $this->travelTo('2026-10-21 10:00:00'); // Manila (app timezone)
        $this->page('Page Uno', '1001');
        $this->page('Page Dos', '2002');
    }

    private function settings(): PageSettings
    {
        return app(PageSettings::class);
    }

    public function test_S_099_6_a_fields_history_lists_its_versions_newest_first_with_who_and_when(): void
    {
        $ana = $this->user('CEO', 'ana@example.test');
        $ben = $this->user('CEO', 'ben@example.test');
        DB::table('users')->where('id', $ana->id)->update(['name' => 'Ana']);
        DB::table('users')->where('id', $ben->id)->update(['name' => 'Ben']);
        $this->settings()->set('Page Uno', 'chatbot', 'price', '399', false, $ana->id);
        $this->travel(5)->minutes();
        $this->settings()->set('Page Uno', 'chatbot', 'price', '499', false, $ben->id);
        $this->settings()->set('Page Dos', 'chatbot', 'price', '1');
        $this->settings()->set('Page Uno', 'chatbot', 'secret_note', 'hush', true, $ana->id);

        $this->actingAs($ana);
        $v = $this->getJson('/chatbot/history?page_name=page%20uno&key=price')->assertOk()->json('versions');
        $this->assertSame([
            ['value' => '499', 'current' => true, 'by' => 'Ben', 'at' => '2026-10-21 10:05'],
            ['value' => '399', 'current' => false, 'by' => 'Ana', 'at' => '2026-10-21 10:00'],
        ], array_map(fn ($x) => array_intersect_key($x, ['value' => 1, 'by' => 1, 'at' => 1, 'current' => 1]), $v));

        $secret = $this->getJson('/chatbot/history?page_name=Page%20Uno&key=secret_note')->assertOk()->json('versions');
        $this->assertSame(['(hidden)', true], [$secret[0]['value'], $secret[0]['secret']]);
        $this->assertStringNotContainsString('hush', $this->getJson('/chatbot/history?page_name=Page%20Uno&key=secret_note')->getContent());
    }

    public function test_S_099_7_restore_writes_the_old_value_back_as_a_new_row_and_refuses_what_a_cell_cannot_take(): void
    {
        $this->settings()->set('Page Uno', 'chatbot', 'price', '399');
        $old = PageSetting::where('key', 'price')->first();
        $this->settings()->set('Page Uno', 'chatbot', 'price', '499');
        $user = $this->user();
        $this->actingAs($user);

        $this->postJson('/chatbot/restore', ['id' => $old->id])->assertOk()->assertJson(['status' => 'replaced', 'value' => '399']);
        $rows = PageSetting::where('key', 'price')->orderBy('id')->get();
        $this->assertSame([['399', false], ['499', false], ['399', true]], $rows->map(fn ($r) => [$r->value, $r->active])->all());
        $this->assertSame($user->id, (int) $rows->last()->created_by);

        $this->settings()->set('Page Uno', 'chatbot', 'secret_note', 'hush', true);
        $this->settings()->set('Page Uno', 'chatbot', 'secret_note', 'hush 2', true);
        $refused = [
            PageSetting::where('key', 'secret_note')->orderBy('id')->first()->id,
            PageSetting::where('provider', 'botcake')->where('key', 'page_id')->first()->id,
            PageSetting::where('provider', 'botcake')->where('key', 'test_ok')->first()->id,
        ];
        $this->settings()->set('Page Uno', 'chatbot', 'flow', 'Old flow');
        $this->settings()->set('Page Uno', 'chatbot', 'active', '0', false, null, true);
        $this->settings()->set('Page Uno', 'chatbot', 'active', '1', false, null, true);
        $refused[] = PageSetting::where('key', 'flow')->first()->id;
        $refused[] = PageSetting::where('key', 'active')->first()->id;
        $before = PageSetting::count();
        foreach ($refused as $id) $this->postJson('/chatbot/restore', ['id' => $id])->assertStatus(422);
        $this->postJson('/chatbot/restore', ['id' => 999999])->assertStatus(422);
        $this->assertSame($before, PageSetting::count());
    }

    public function test_S_099_8_the_changes_log_pairs_old_and_new_filters_paginates_and_hides_secrets(): void
    {
        $ana = $this->user('CEO', 'ana@example.test');
        $ben = $this->user('CEO', 'ben@example.test');
        $s = $this->settings();
        $s->set('Page Uno', 'chatbot', 'price', '399', false, $ana->id);
        $this->travel(1)->days();
        $s->set('Page Uno', 'chatbot', 'price', '499', false, $ben->id);
        $s->set('Page Dos', 'chatbot', 'main_flow', 'Hello', false, $ana->id);
        $s->set('Page Uno', 'chatbot', 'secret_note', 'hush', true, $ana->id);
        $s->set('Page Uno', 'chatbot', 'secret_note', 'hush 2', true, $ana->id);
        $this->actingAs($ana);

        $all = $this->getJson('/chatbot/changes')->assertOk()->json();
        $this->assertStringNotContainsString('hush', json_encode($all));
        $pick = fn ($e) => [$e['page'], $e['field'], $e['old'], $e['new']];
        $chatbot = array_values(array_filter($all['entries'], fn ($e) => $e['provider'] === 'chatbot'));
        $this->assertSame([
            ['Page Uno', 'Secret Note', '(hidden)', '(hidden)'],
            ['Page Uno', 'Secret Note', null, '(hidden)'],
            ['Page Dos', 'Main Flow', null, 'Hello'],
            ['Page Uno', 'PRICE', '399', '499'],
            ['Page Uno', 'PRICE', null, '399'],
        ], array_map($pick, $chatbot));
        $this->assertTrue(collect($all['entries'])->contains(fn ($e) => $e['provider'] === 'botcake' && $e['field'] === 'Botcake ID' && $e['new'] === '1001'));
        $this->assertFalse(collect($all['entries'])->contains(fn ($e) => in_array($e['field'], ['test_ok', 'flow.shipping'], true)), 'botcake: ang nasa grid lang');
        $token = collect($all['entries'])->firstWhere('field', 'Botcake token');
        $this->assertSame('(hidden)', $token['new']);

        $byAna = $this->getJson('/chatbot/changes?user=' . $ana->id . '&page=page%20uno&field=price')->json('entries');
        $this->assertSame([['Page Uno', 'PRICE', null, '399']], array_map($pick, $byAna));
        $this->assertEqualsCanonicalizing([$ana->id, $ben->id], array_column($all['users'], 'id'), 'ang mga user sa filter: ang may naitalang edit');
        $dated = $this->getJson('/chatbot/changes?field=price&from=2026-10-22&to=2026-10-22')->json('entries');
        $this->assertSame([['Page Uno', 'PRICE', '399', '499']], array_map($pick, $dated));

        for ($i = 1; $i <= 55; $i++) $s->set('Page Dos', 'chatbot', 'quantity', (string) $i);
        $p1 = $this->getJson('/chatbot/changes?field=quantity')->json();
        $this->assertSame([50, 55, 2], [count($p1['entries']), $p1['total'], $p1['last_page']]);
        $this->assertSame(['54', '55'], [$p1['entries'][0]['old'], $p1['entries'][0]['new']]);
        $p2 = $this->getJson('/chatbot/changes?field=quantity&p=2')->json('entries');
        $this->assertSame([[null, '1'], ['1', '2'], ['2', '3'], ['3', '4'], ['4', '5']], array_reverse(array_map(fn ($e) => [$e['old'], $e['new']], $p2)));
    }

    public function test_S_099_9_every_new_endpoint_needs_chatblast_access(): void
    {
        $id = PageSetting::first()->id;
        $calls = [
            ['GET', '/chatbot/check?page_name=Page%20Uno'], ['POST', '/chatbot/check', ['page_name' => 'Page Uno']],
            ['GET', '/chatbot/preview?page_name=Page%20Uno'], ['GET', '/chatbot/history?page_name=Page%20Uno&key=price'],
            ['POST', '/chatbot/restore', ['id' => $id]], ['GET', '/chatbot/changes'],
        ];
        foreach ($calls as $c) $this->json($c[0], $c[1], $c[2] ?? [])->assertUnauthorized();
        $this->actingAs($this->user('Data Encoder', 'enc@example.test'));
        $before = PageSetting::count();
        foreach ($calls as $c) $this->json($c[0], $c[1], $c[2] ?? [])->assertForbidden();
        $this->assertSame($before, PageSetting::count());
    }

    public function test_S_099_10_preview_fills_the_main_texts_for_a_sample_customer_with_images(): void
    {
        $s = $this->settings();
        $s->set('Page Uno', 'chatbot', 'price', '499');
        $s->set('Page Uno', 'chatbot', 'picture', 'https://img.example.test/p.jpg');
        $s->set('Page Uno', 'chatbot', 'main_flow', 'Hi {{first_name}}! Only {{PRICE}}.');
        $s->set('Page Uno', 'chatbot', 'fill_up_form', 'Name: {{full_name}}');
        $s->set('Page Uno', 'chatbot', 'seq_2', 'Step two for {{Page Name}}');
        $s->set('Page Uno', 'chatbot', 'seq_img_2', 'https://img.example.test/2.jpg');
        $s->set('Page Uno', 'chatbot', 'prompt_1', 'not a customer text');
        $this->actingAs($this->user());
        $before = PageSetting::count();

        $items = $this->getJson('/chatbot/preview?page_name=Page%20Uno')->assertOk()->json('items');
        $this->assertSame([
            ['label' => 'PICTURE', 'text' => null, 'image' => 'https://img.example.test/p.jpg'],
            ['label' => 'Main Flow', 'text' => 'Hi Juan! Only 499.', 'image' => null],
            ['label' => 'Fill-Up Form', 'text' => 'Name: Juan Dela Cruz', 'image' => null],
            ['label' => 'Seq 2', 'text' => 'Step two for Page Uno', 'image' => 'https://img.example.test/2.jpg'],
        ], $items);
        $this->assertSame($before, PageSetting::count());
        $this->getJson('/chatbot/preview?page_name=Nope')->assertStatus(422);
    }

    public function test_S_120_2_a_cleared_value_shows_in_changes_with_who_and_when(): void
    {
        $ana = $this->user('CEO', 'ana@example.test');
        DB::table('users')->where('id', $ana->id)->update(['name' => 'Ana']);
        $this->settings()->set('Page Uno', 'chatbot', 'price', '399', false, $ana->id);
        $this->travel(5)->minutes();
        $this->settings()->deactivate(PageSetting::where('key', 'price')->where('active', true)->first(), $ana->id);
        $this->settings()->set('Page Dos', 'chatbot', 'price', '1');
        $this->settings()->set('Page Dos', 'chatbot', 'price', '2'); // replaced, not cleared
        $this->actingAs($ana);

        $res = $this->getJson('/chatbot/changes?page=page%20uno&field=price')->assertOk();
        $this->assertSame([['399', '(none)', 'Ana', '2026-10-21 10:05'], [null, '399', 'Ana', '2026-10-21 10:00']],
            collect($res->json('entries'))->map(fn ($x) => [$x['old'], $x['new'], $x['by'], $x['at']])->all());
        $this->assertSame(2, $res->json('total'));
        $this->assertCount(2, $this->getJson('/chatbot/changes?page=page%20dos&field=price')->json('entries'), 'a replaced value is not a clear');
    }
}

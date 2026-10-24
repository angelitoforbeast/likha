<?php

namespace Tests\Feature\Chatblast;

use App\Models\ChatbotFlow;
use App\Models\PageSetting;
use App\Services\Chatbot\ChatbotPage;
use App\Services\PageSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Ang Changes ng /chatbot/pages: lahat ng galaw (sulat at clear), hindi natatabunan ng mas bagong value, kasama ang FLOW
 * at ang ACTIVE switch.
 */
class ChatbotAllChangesTest extends ChatblastTestCase
{
    private $ana;
    private $ben;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->travelTo('2026-10-24 10:00:00');
        $this->page('Page Uno', '1001');
        $this->ana = $this->user('CEO', 'ana@example.test');
        $this->ben = $this->user('CEO', 'ben@example.test');
        DB::table('users')->where('id', $this->ana->id)->update(['name' => 'Ana']);
        DB::table('users')->where('id', $this->ben->id)->update(['name' => 'Ben']);
        $this->actingAs($this->ana);
    }

    private function settings(): PageSettings
    {
        return app(PageSettings::class);
    }

    private function changes(string $query = ''): array
    {
        return $this->getJson('/chatbot/changes' . ($query === '' ? '' : '?' . $query))->assertOk()->json();
    }

    private function rows(array $entries): array
    {
        return array_map(fn ($e) => [$e['at'], $e['by'], $e['old'], $e['new']], $entries);
    }

    private function link(?string $flow, int $userId): void
    {
        $model = $flow === null ? null : (new ChatbotFlow())->forceFill(['id' => 7, 'name' => $flow]);
        app(ChatbotPage::class)->link('Page Uno', $model, $userId);
    }

    private function clear(string $key, int $userId): void
    {
        $this->settings()->deactivate(PageSetting::where('page_name', 'Page Uno')->where('key', $key)->where('active', true)->first(), $userId);
    }

    /** Set, alis, set ulit: tatlong linya; ang alis ay hindi nawawala at ang "before" ng huling set ay (none). */
    private function hanna(): void
    {
        $this->travelTo('2026-10-24 16:07:00');
        $this->link('Main Flow', $this->ana->id);
        $this->travelTo('2026-10-24 19:02:00');
        $this->link(null, $this->ana->id);
        $this->travelTo('2026-10-25 06:52:00');
        $this->link('Main Flow', $this->ben->id);
    }

    public function test_S_146_1_a_removed_flow_stays_listed_after_it_is_set_again(): void
    {
        $this->hanna();

        $this->assertSame([
            ['2026-10-25 06:52', 'Ben', '(none)', 'Main Flow'],
            ['2026-10-24 19:02', 'Ana', 'Main Flow', '(none)'],
            ['2026-10-24 16:07', 'Ana', null, 'Main Flow'],
        ], $this->rows($this->changes('field=flow')['entries']));
        $this->assertFalse(collect($this->changes()['entries'])->contains('key', 'flow_id'), 'flow_id: walang dobleng linya');
    }

    public function test_S_146_2_a_replacement_is_one_line_without_a_clear(): void
    {
        $this->travelTo('2026-10-24 11:00:00');
        $this->settings()->set('Page Uno', 'chatbot', 'price', '399', false, $this->ana->id);
        $this->travelTo('2026-10-24 11:05:00');
        $this->settings()->set('Page Uno', 'chatbot', 'price', '499', false, $this->ben->id);

        $res = $this->changes('field=price');
        $this->assertSame([['2026-10-24 11:05', 'Ben', '399', '499'], ['2026-10-24 11:00', 'Ana', null, '399']], $this->rows($res['entries']));
        $this->assertSame(2, $res['total']);
    }

    public function test_S_146_3_the_bot_switch_shows_on_and_off_with_the_user(): void
    {
        Http::fake(['botcake.io/*' => Http::response(['success' => true])]);
        $this->travelTo('2026-10-24 12:00:00');
        $this->postJson('/chatbot/active', ['page_name' => 'Page Uno', 'on' => true])->assertOk()->assertJson(['active' => true]);
        $this->travelTo('2026-10-24 12:30:00');
        $this->actingAs($this->ben)->postJson('/chatbot/active', ['page_name' => 'Page Uno', 'on' => false])->assertOk()->assertJson(['active' => false]);

        $entries = $this->changes('field=active')['entries'];
        $this->assertSame([['2026-10-24 12:30', 'Ben', 'On', 'Off'], ['2026-10-24 12:00', 'Ana', null, 'On']], $this->rows($entries));
        $this->assertSame('ACTIVE', $entries[0]['field']);
        $this->assertContains('active', array_column($this->changes()['fields'], 'key'));
    }

    public function test_S_146_4_the_user_filter_finds_the_remover_and_the_field_filter_finds_every_event(): void
    {
        $this->hanna();
        $this->settings()->set('Page Uno', 'chatbot', 'price', '399', false, $this->ben->id);
        $this->travel(1)->minutes();
        $this->clear('price', $this->ana->id);

        $this->assertSame([['2026-10-25 06:53', 'Ana', '399', '(none)']], $this->rows($this->changes('field=price&user=' . $this->ana->id)['entries']));
        $this->assertSame(['2026-10-24 19:02', '2026-10-24 16:07'], array_column($this->changes('field=flow&user=' . $this->ana->id)['entries'], 'at'));
        $this->assertSame(3, $this->changes('field=flow')['total']);
        $this->assertEqualsCanonicalizing([$this->ana->id, $this->ben->id], array_column($this->changes()['users'], 'id'));
    }

    public function test_S_146_5_the_date_filter_uses_the_time_of_the_clear(): void
    {
        $this->travelTo('2026-10-23 09:00:00');
        $this->settings()->set('Page Uno', 'chatbot', 'price', '399', false, $this->ben->id);
        $this->travelTo('2026-10-24 09:00:00');
        $this->clear('price', $this->ana->id);

        $this->assertSame([['2026-10-24 09:00', 'Ana', '399', '(none)']], $this->rows($this->changes('field=price&from=2026-10-24&to=2026-10-24')['entries']));
        $this->assertSame([['2026-10-23 09:00', 'Ben', null, '399']], $this->rows($this->changes('field=price&from=2026-10-23&to=2026-10-23')['entries']));
    }

    public function test_S_146_6_secret_values_stay_masked_in_writes_and_clears(): void
    {
        $this->settings()->set('Page Uno', 'chatbot', 'secret_note', 'hush-one', true, $this->ana->id);
        $this->travel(1)->minutes();
        $this->clear('secret_note', $this->ana->id);
        $this->travel(1)->minutes();
        $this->settings()->set('Page Uno', 'chatbot', 'secret_note', 'hush-two', true, $this->ben->id);
        $this->settings()->set('Page Uno', 'botcake', 'token', 'tok-secret-2', true, $this->ben->id);

        $all = $this->changes();
        $this->assertStringNotContainsString('hush', json_encode($all));
        $this->assertStringNotContainsString('tok-secret', json_encode($all));
        $this->assertSame([['(none)', '(hidden)'], ['(hidden)', '(none)'], [null, '(hidden)']],
            array_map(fn ($e) => [$e['old'], $e['new']], $this->changes('field=secret_note')['entries']));
        $this->assertSame(['(hidden)', '(hidden)'], [$this->changes('field=botcake.token')['entries'][0]['old'], $this->changes('field=botcake.token')['entries'][0]['new']]);
    }

    public function test_S_146_7_paging_across_writes_and_clears_is_stable(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->travel(1)->minutes();
            $this->settings()->set('Page Uno', 'chatbot', 'quantity', (string) $i, false, $this->ana->id);
            $this->travel(1)->minutes();
            $this->clear('quantity', $this->ben->id);
        }

        $p1 = $this->changes('field=quantity');
        $p2 = $this->changes('field=quantity&p=2');
        $this->assertSame([60, 2, 50, 10], [$p1['total'], $p1['last_page'], count($p1['entries']), count($p2['entries'])]);
        $all = array_merge($p1['entries'], $p2['entries']);
        $this->assertSame(['30', '(none)'], [$all[0]['old'], $all[0]['new']]);
        $this->assertSame([null, '1'], [$all[59]['old'], $all[59]['new']]);
        $this->assertSame(['(none)', '2'], [$all[57]['old'], $all[57]['new']]);
        $at = array_column($all, 'at');
        $this->assertSame(60, count(array_unique($at)), 'walang ulit sa dalawang pahina');
        $sorted = $at;
        rsort($sorted);
        $this->assertSame($sorted, $at);
    }
}
